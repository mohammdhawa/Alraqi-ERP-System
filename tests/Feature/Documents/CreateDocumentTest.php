<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Approvals\Enums\RejectionPolicy;
use App\Modules\Approvals\Enums\SkipReason;
use App\Modules\Approvals\Enums\StepStatus;
use App\Modules\Auth\Models\Notification;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\NotificationService;
use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Enums\DocumentKind;
use App\Modules\Documents\Enums\DocumentOrigin;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Pdf\Repair\PdfRepairer;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Tests\Support\Pdfs;
use Tests\TestCase;

/**
 * Sending a document between managers for signing (documents design §7.1):
 * who may, who may be named, what is stored, and that a refusal leaves
 * nothing behind.
 */
class CreateDocumentTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Employee> */
    private array $people = [];

    /** @var array<string, User> */
    private array $accounts = [];

    private Department $root;

    private Department $division;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->root = Department::create(['name' => 'الإدارة العامة', 'level' => 1]);
        $this->division = Department::create(['name' => 'إدارة المالية', 'level' => 2, 'parent_id' => $this->root->id, 'sector' => 1]);

        $this->manage('gm', $this->root);
        $this->manage('c', $this->division);

        foreach (['a1' => 'قسم الحسابات', 'a2' => 'قسم الميزانية'] as $key => $name) {
            $this->manage($key, Department::create(['name' => $name, 'level' => 3, 'parent_id' => $this->division->id]));
        }

        $this->person('plain');
    }

    private function person(string $key): Employee
    {
        $employee = Employee::factory()->create();
        $this->people[$key] = $employee;
        $this->accounts[$key] = User::factory()->create(['employee_id' => $employee->id, 'must_change_password' => false]);

        return $employee;
    }

    private function manage(string $key, Department $unit): void
    {
        $unit->update(['manager_id' => $this->person($key)->id]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title'     => 'تعميم داخلي',
            'file'      => UploadedFile::fake()->createWithContent('letter.pdf', Pdfs::plain()),
            'approvers' => [$this->people['a1']->id, $this->people['a2']->id],
        ], $overrides);
    }

    private function send(string $as, array $payload): TestResponse
    {
        Sanctum::actingAs($this->accounts[$as]);

        return $this->post('/api/documents', $payload, ['Accept' => 'application/json']);
    }

    /**
     * @return array<int, string>
     */
    private function filesUnder(string $directory): array
    {
        return Storage::disk('local')->allFiles($directory);
    }

    public function test_a_manager_sends_a_document_for_signing(): void
    {
        $response = $this->send('c', $this->payload(['description' => 'للتوقيع', 'import_number' => 12]));

        $response->assertCreated()->assertJsonPath('message', 'تم إرسال المستند للاعتماد.');

        $document = Document::query()->firstOrFail();
        $this->assertSame(DocumentKind::Internal, $document->kind);
        $this->assertSame(DocumentOrigin::Uploaded, $document->origin);
        $this->assertSame(DocumentStatus::Pending, $document->status);
        $this->assertSame($this->division->id, $document->creator_unit_id);
        $this->assertSame($this->division->id, $document->register_unit_id);
        $this->assertSame($this->people['c']->id, $document->creator_id);
        $this->assertSame($this->accounts['c']->id, $document->created_by);
        $this->assertSame(12, $document->import_number);
        $this->assertNull($document->uploaded_path);
        $this->assertSame("documents/{$document->folder}/original.pdf", $document->original_path);
        $this->assertStringStartsWith('%PDF', (string) Storage::disk('local')->get($document->original_path));

        $approval = $document->approval;
        $this->assertSame(RejectionPolicy::Advisory, $approval->rejection_policy);
        $steps = $approval->steps;
        $this->assertCount(3, $steps);
        $this->assertSame([$this->people['a1']->id, $this->people['a2']->id, $this->people['gm']->id], $steps->pluck('approver_id')->all());
        $this->assertSame([1, 2, 3], $steps->pluck('step_order')->all());
        $this->assertSame([false, false, true], $steps->pluck('decisive')->all());
        $this->assertSame(1, $approval->currentOrder());

        $notification = Notification::query()->where('user_id', $this->accounts['a1']->id)->firstOrFail();
        $this->assertSame('مستند جديد بانتظار موافقتك', $notification->title);
        $this->assertSame('يرجى مراجعة: تعميم داخلي', $notification->body);
        $this->assertSame('document', $notification->reference_type);
        $this->assertSame(0, Notification::query()->where('user_id', $this->accounts['a2']->id)->count());

        $response->assertJsonPath('data.id', $document->id)
            ->assertJsonPath('data.title', 'تعميم داخلي')
            ->assertJsonPath('data.status.value', DocumentStatus::Pending->value)
            ->assertJsonPath('data.creator.id', $this->people['c']->id)
            ->assertJsonPath('data.creator_unit.id', $this->division->id)
            ->assertJsonPath('data.has_printed_copy', false)
            ->assertJsonCount(3, 'data.steps')
            ->assertJsonPath('data.steps.2.decisive', true)
            ->assertJsonPath('data.can.decide', false)
            ->assertJsonPath('data.can.delete', true);
    }

    /** Only template documents are numbered (D3.3); an upload carries none of a template's columns. */
    public function test_an_upload_has_no_template_numbers(): void
    {
        $data = $this->send('c', $this->payload())->assertCreated()->json('data');

        foreach (['export_number', 'reference', 'template', 'field_values'] as $key) {
            $this->assertArrayHasKey($key, $data);
            $this->assertNull($data[$key], $key);
        }

        $document = Document::query()->firstOrFail();
        $this->assertArrayHasKey('card_floor', $document->getAttributes());
        $this->assertNull($document->template_id);
        $this->assertNull($document->template_version);
        $this->assertNull($document->export_number);
        $this->assertNull($document->reference);
        $this->assertNull($document->card_floor);
        $this->assertSame(0, DB::table('document_counters')->count());
        $this->assertSame(0, DB::table('document_reference_sequences')->count());
    }

    /**
     * Ruling R17: an upload's text end is unknown, so it records none and
     * its cards keep piece 2's placement.
     */
    public function test_an_upload_records_no_content_bottom(): void
    {
        $this->send('c', $this->payload())->assertCreated();

        $document = Document::query()->firstOrFail();
        $this->assertArrayHasKey('content_bottom', $document->getAttributes());
        $this->assertNull($document->content_bottom);
    }

    public function test_only_a_unit_manager_creates(): void
    {
        $this->send('plain', $this->payload())
            ->assertForbidden()
            ->assertJsonPath('message', 'لا يُنشئ المستندات إلا مدير وحدة تنظيمية.');

        $this->assertSame(0, Document::query()->count());
    }

    public function test_a_manager_of_an_inactive_unit_cannot_send(): void
    {
        $this->division->update(['is_active' => false]);

        $this->send('c', $this->payload())
            ->assertForbidden()
            ->assertJsonPath('message', 'لا يُنشئ المستندات إلا مدير وحدة تنظيمية.');

        $this->assertSame(0, Document::query()->count());
    }

    public function test_a_manager_of_an_inactive_unit_cannot_be_named(): void
    {
        Department::query()->where('manager_id', $this->people['a1']->id)->update(['is_active' => false]);

        $a1 = $this->people['a1'];
        $this->send('c', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('message', "لا يمكن اختيار «{$a1->full_name}» معتمداً لأنه لا يدير وحدة تنظيمية.");

        $this->assertSame(0, Document::query()->count());
    }

    public function test_approvers_must_be_unit_managers_other_than_the_creator_and_the_gm(): void
    {
        $plain = $this->people['plain'];
        $this->send('c', $this->payload(['approvers' => [$plain->id]]))
            ->assertStatus(422)
            ->assertJsonPath('message', "لا يمكن اختيار «{$plain->full_name}» معتمداً لأنه لا يدير وحدة تنظيمية.");

        $this->send('c', $this->payload(['approvers' => [$this->people['c']->id]]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكنك اختيار نفسك معتمداً.');

        $this->send('c', $this->payload(['approvers' => [$this->people['gm']->id]]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'المدير العام يُضاف تلقائياً في آخر المسار، فلا تختره بين المعتمدين.');

        $this->send('c', $this->payload(['approvers' => [$this->people['a1']->id, $this->people['a1']->id]]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن تكرار المعتمد نفسه.');

        $this->send('c', $this->payload(['approvers' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('approvers');

        $this->assertSame(0, Document::query()->count());
    }

    public function test_the_request_is_validated(): void
    {
        $this->send('c', $this->payload(['title' => '']))
            ->assertStatus(422)->assertJsonPath('errors.title.0', 'عنوان المستند مطلوب.');

        $this->send('c', $this->payload(['file' => null]))
            ->assertStatus(422)->assertJsonPath('errors.file.0', 'الملف مطلوب.');

        $this->send('c', $this->payload(['approvers' => range(1, 11)]))
            ->assertStatus(422)->assertJsonPath('errors.approvers.0', 'لا يزيد عدد المعتمدين على 10.');

        $this->send('c', $this->payload(['approvers' => null]))
            ->assertStatus(422)->assertJsonPath('errors.approvers.0', 'اختر معتمداً واحداً على الأقل.');
    }

    public function test_a_vacant_general_manager_post_refuses_sending(): void
    {
        $this->root->update(['manager_id' => null]);

        $this->send('c', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يوجد مدير عام في الهيكل التنظيمي، فلا يمكن إرسال المستند.');
    }

    public function test_the_general_manager_s_own_document_skips_his_step(): void
    {
        $this->send('gm', $this->payload(['approvers' => [$this->people['a1']->id]]))->assertCreated();

        $steps = Document::query()->firstOrFail()->approval->steps;

        $this->assertCount(2, $steps);
        $this->assertSame(StepStatus::Pending, $steps[0]->status);
        $this->assertSame(StepStatus::Skipped, $steps[1]->status);
        $this->assertSame(SkipReason::Requester, $steps[1]->skip_reason);
        $this->assertTrue($steps[1]->decisive);
    }

    public function test_a_repaired_pdf_keeps_the_uploaded_file(): void
    {
        $this->app->instance(PdfRepairer::class, new class implements PdfRepairer
        {
            public function repair(string $source, string $target): bool
            {
                file_put_contents($target, Pdfs::plain());

                return true;
            }
        });

        $this->send('c', $this->payload(['file' => UploadedFile::fake()->createWithContent('scan.pdf', Pdfs::withXrefStream())]))
            ->assertCreated();

        $document = Document::query()->firstOrFail();
        $this->assertSame("documents/{$document->folder}/uploaded.pdf", $document->uploaded_path);
        Storage::disk('local')->assertExists($document->original_path);
        Storage::disk('local')->assertExists($document->uploaded_path);
        $this->assertSame(Pdfs::withXrefStream(), Storage::disk('local')->get($document->uploaded_path));
    }

    public function test_a_photo_is_sent_as_a_pdf(): void
    {
        $this->send('c', $this->payload(['file' => UploadedFile::fake()->image('photo.jpg', 200, 300)]))->assertCreated();

        $document = Document::query()->firstOrFail();
        $this->assertStringStartsWith('%PDF', (string) Storage::disk('local')->get($document->original_path));
        $this->assertNull($document->uploaded_path);
    }

    public function test_a_word_file_is_refused(): void
    {
        $this->send('c', $this->payload(['file' => UploadedFile::fake()->create('letter.docx', 20, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'يُرجى رفع الملف بصيغة PDF أو صورة.');

        $this->assertSame(0, Document::query()->count());
        $this->assertSame([], $this->filesUnder('documents'));
    }

    public function test_supporting_attachments_up_to_ten(): void
    {
        $files = fn (int $count): array => array_map(
            static fn (int $i): UploadedFile => UploadedFile::fake()->create("note-{$i}.pdf", 10, 'application/pdf'),
            range(1, $count),
        );

        $this->send('c', $this->payload(['attachments' => $files(10)]))
            ->assertCreated()
            ->assertJsonCount(10, 'data.attachments');

        $document = Document::query()->firstOrFail();
        $this->assertSame(10, $document->attachments()->count());
        $this->assertCount(10, $this->filesUnder('attachments/document/'.$document->id));

        $this->send('c', $this->payload(['attachments' => $files(11)]))
            ->assertStatus(422)
            ->assertJsonPath('errors.attachments.0', 'لا تزيد المرفقات على 10.');
    }

    public function test_an_import_number_is_unique_in_the_register(): void
    {
        $this->send('c', $this->payload(['import_number' => 77]))->assertCreated();

        $this->send('c', $this->payload(['import_number' => 77]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'رقم الوارد 77 مستخدم في سجل هذه الإدارة.');

        $this->assertSame(1, Document::query()->count());
        $this->assertCount(1, $this->filesUnder('documents'));
    }

    public function test_nothing_is_left_on_disk_when_sending_fails(): void
    {
        $this->root->update(['manager_id' => null]);

        $this->send('c', $this->payload(['attachments' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]]))
            ->assertStatus(422);

        $this->assertSame([], $this->filesUnder('documents'));
        $this->assertSame([], $this->filesUnder('attachments'));
    }

    public function test_the_policy_and_the_approver_side_of_a_document(): void
    {
        $this->send('c', $this->payload())->assertCreated();
        $document = Document::query()->firstOrFail();

        $this->assertTrue($this->accounts['c']->can('view', $document));
        $this->assertTrue($this->accounts['a2']->can('view', $document));
        $this->assertFalse($this->accounts['plain']->can('view', $document));
        $this->assertTrue($this->accounts['c']->can('delete', $document));
        $this->assertFalse($this->accounts['a1']->can('delete', $document));

        $this->assertSame('تعميم داخلي', $document->approvalSummary()->title);
        $this->assertSame("{$this->people['c']->full_name} · إدارة المالية", $document->approvalSummary()->summary);

        // A1 manages a section with no stamp; the viewer is the signed-in account.
        Sanctum::actingAs($this->accounts['a1']);
        $pending = ['document_status' => 1, 'document_status_label' => 'قيد الاعتماد'];
        $this->assertSame([$document->id => ['stamp_missing' => true] + $pending], Document::approverRowDetails(collect([$document]), false));
        Sanctum::actingAs($this->accounts['plain']);
        $this->assertSame([$document->id => ['stamp_missing' => false] + $pending], Document::approverRowDetails(collect([$document]), false));
    }

    public function test_the_inbox_survives_a_deleted_unit_or_creator(): void
    {
        $this->send('c', $this->payload())->assertCreated();

        // Soft-deleted underneath the document, as a leaf unit or a leaver can be.
        DB::table('departments')->where('id', $this->division->id)->update(['deleted_at' => now()]);
        DB::table('employees')->where('id', $this->people['c']->id)->update(['deleted_at' => now()]);

        Sanctum::actingAs($this->accounts['a1']);
        $response = $this->getJson('/api/me/approvals')->assertOk();

        $this->assertStringContainsString($this->people['c']->full_name, json_encode($response->json(), JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('إدارة المالية', json_encode($response->json(), JSON_UNESCAPED_UNICODE));
    }

    /**
     * The local disk answers false to a write it cannot make (throw => false);
     * a directory standing where the file must go makes one.
     */
    private function sendWithAFileBlocked(string $blocked, UploadedFile $file): void
    {
        $folder = '3f0c1a52-6b7e-4c1d-9a55-0d1e2f3a4b5c';
        Str::createUuidsUsing(static fn () => Uuid::fromString($folder));

        try {
            Storage::fake('local', ['throw' => false]);
            Storage::disk('local')->makeDirectory("documents/{$folder}/{$blocked}");

            $this->send('c', $this->payload(['file' => $file]))->assertStatus(500);
        } finally {
            Str::createUuidsNormally();
        }

        $this->assertSame(0, Document::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('documents'));
        $this->assertSame([], Storage::disk('local')->allFiles('attachments'));
    }

    public function test_a_failed_write_of_the_file_leaves_nothing_behind(): void
    {
        $this->sendWithAFileBlocked('original.pdf', UploadedFile::fake()->createWithContent('letter.pdf', Pdfs::plain()));
    }

    public function test_a_failed_write_of_the_uploaded_copy_leaves_nothing_behind(): void
    {
        $this->app->instance(PdfRepairer::class, new class implements PdfRepairer
        {
            public function repair(string $source, string $target): bool
            {
                file_put_contents($target, Pdfs::plain());

                return true;
            }
        });

        $this->sendWithAFileBlocked('uploaded.pdf', UploadedFile::fake()->createWithContent('scan.pdf', Pdfs::withXrefStream()));
    }

    public function test_a_deleted_documents_import_number_can_be_used_again(): void
    {
        $this->send('c', $this->payload(['import_number' => 77]))->assertCreated();

        $this->send('c', $this->payload(['import_number' => 77]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'رقم الوارد 77 مستخدم في سجل هذه الإدارة.');

        Document::query()->firstOrFail()->delete();

        $this->send('c', $this->payload(['import_number' => 77]))->assertCreated();
        $this->assertSame(1, Document::query()->count());
        $this->assertSame(2, Document::withTrashed()->count());
    }

    public function test_the_same_import_number_in_another_division_is_accepted(): void
    {
        $this->send('c', $this->payload(['import_number' => 77]))->assertCreated();

        $other = Department::create(['name' => 'إدارة الموارد', 'level' => 2, 'parent_id' => $this->root->id, 'sector' => 1]);
        $this->manage('d', $other);

        $this->send('d', $this->payload(['import_number' => 77]))->assertCreated();
        $this->assertSame(2, Document::query()->where('import_number', 77)->count());
    }

    public function test_a_failure_after_the_commit_keeps_the_stored_files(): void
    {
        // Laravel runs after-commit callbacks after the commit, still inside
        // transaction(): a push job that cannot be queued throws from there.
        $this->app->instance(NotificationService::class, new class extends NotificationService
        {
            public function sendToUsers(array $userIds, string $title, ?string $body = null, ?string $type = null, ?Model $reference = null): int
            {
                $sent = parent::sendToUsers($userIds, $title, $body, $type, $reference);

                DB::afterCommit(static fn () => throw new RuntimeException('The queue is down.'));

                return $sent;
            }
        });

        $this->send('c', $this->payload(['attachments' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]]));

        // The document is committed — pending, its first approver told — so
        // the files it names must be there.
        $document = Document::query()->firstOrFail();
        $this->assertSame(DocumentStatus::Pending, $document->status);
        $this->assertSame(1, Notification::query()->where('user_id', $this->accounts['a1']->id)->count());
        Storage::disk('local')->assertExists((string) $document->original_path);

        $attachments = $document->attachments()->get();
        $this->assertCount(1, $attachments);
        Storage::disk('local')->assertExists($attachments[0]->path);
    }

    public function test_nothing_is_left_on_disk_when_the_engine_refuses_after_the_files_are_stored(): void
    {
        // The first approver has no active account: the engine refuses on
        // submission, after the file and the attachment were written.
        User::query()->where('employee_id', $this->people['a1']->id)->update(['is_active' => false]);

        $this->send('c', $this->payload(['attachments' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]]))
            ->assertStatus(422);

        $this->assertSame(0, Document::query()->count());
        $this->assertSame([], $this->filesUnder('documents'));
        $this->assertSame([], $this->filesUnder('attachments'));
    }
}
