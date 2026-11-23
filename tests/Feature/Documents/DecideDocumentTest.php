<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Approvals\Enums\StepStatus;
use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Auth\Models\Notification;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\NotificationService;
use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Enums\Confirmation;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Enums\MarkKind;
use App\Modules\Documents\Enums\SignatureSource;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentMark;
use App\Modules\Documents\Models\UnitStamp;
use App\Modules\Documents\Pdf\CardLayout;
use App\Modules\Documents\Pdf\DocumentPrinter;
use App\Modules\Documents\Pdf\PdfWorkspace;
use App\Modules\Documents\Services\Signatures;
use App\Modules\Documents\Services\UnitStamps;
use App\Modules\HR\Models\Employee;
use App\Shared\Support\NotificationEventCatalogue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
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
 * Deciding a document between managers (documents design §7.2): the checks in
 * their order, one transaction for the decision, its card and the printed
 * copy, and legacy's words to the next approver and the creator.
 */
class DecideDocumentTest extends TestCase
{
    use RefreshDatabase;

    private const TITLE = 'تعميم داخلي';

    /** @var array<string, Employee> */
    private array $people = [];

    /** @var array<string, User> */
    private array $accounts = [];

    /** @var array<string, Department> */
    private array $units = [];

    private Document $document;

    protected function setUp(): void
    {
        parent::setUp();

        // As the local disk is configured: a write it cannot make answers false.
        Storage::fake('local', ['throw' => false]);

        $this->units['gm'] = Department::create(['name' => 'الإدارة العامة', 'level' => 1]);
        $this->units['c'] = Department::create(['name' => 'إدارة المالية', 'level' => 2, 'parent_id' => $this->units['gm']->id, 'sector' => 1]);
        $this->units['a1'] = Department::create(['name' => 'قسم الحسابات', 'level' => 3, 'parent_id' => $this->units['c']->id]);
        $this->units['a2'] = Department::create(['name' => 'قسم الميزانية', 'level' => 3, 'parent_id' => $this->units['c']->id]);

        // Every manager has a sealed signature and their unit a stamp.
        foreach ($this->units as $key => $unit) {
            $employee = Employee::factory()->create();
            $this->people[$key] = $employee;
            $this->accounts[$key] = User::factory()->create(['employee_id' => $employee->id, 'must_change_password' => false]);
            $unit->update(['manager_id' => $employee->id]);

            app(UnitStamps::class)->replace($unit, UploadedFile::fake()->image('stamp.png', 300, 300), $this->accounts[$key]);
            app(Signatures::class)->draw($employee, UploadedFile::fake()->image('s.png', 600, 200), SignatureSource::App, $this->accounts[$key]);
        }
    }

    /**
     * @param  array<int, string>  $approvers
     */
    private function send(string $as = 'c', array $approvers = ['a1', 'a2']): Document
    {
        Sanctum::actingAs($this->accounts[$as]);

        $this->post('/api/documents', [
            'title'     => self::TITLE,
            'file'      => UploadedFile::fake()->createWithContent('letter.pdf', Pdfs::plain()),
            'approvers' => array_map(fn (string $key): int => $this->people[$key]->id, $approvers),
        ], ['Accept' => 'application/json'])->assertCreated();

        return $this->document = Document::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function decide(string $as, string $decision = 'approve', array $body = []): TestResponse
    {
        Sanctum::actingAs($this->accounts[$as]);

        return $this->postJson(
            "/api/documents/{$this->document->id}/decision",
            array_merge(['decision' => $decision, 'confirmed_by' => Confirmation::Biometric->value], $body),
        );
    }

    private function stepOf(string $key): ApprovalStep
    {
        return $this->document->approval()->firstOrFail()->steps()->where('approver_id', $this->people[$key]->id)->firstOrFail();
    }

    /**
     * @return array<int, string>
     */
    private function printedCopies(): array
    {
        return Storage::disk('local')->allFiles("documents/{$this->document->folder}/printed");
    }

    /**
     * @return Collection<int, Notification>
     */
    private function notificationsOf(User $account, string $title)
    {
        return Notification::query()->where('user_id', $account->id)->where('title', $title)->get();
    }

    private function assertToldOnce(string $key, string $title, string $body, ?string $type = null): void
    {
        $notifications = $this->notificationsOf($this->accounts[$key], $title);

        $this->assertCount(1, $notifications, "{$key} should be told «{$title}» once.");
        $this->assertSame($body, $notifications[0]->body);
        $this->assertSame('document', $notifications[0]->reference_type);
        $this->assertSame($this->document->id, (int) $notifications[0]->reference_id);

        if ($type !== null) {
            $this->assertSame($type, $notifications[0]->type);
        }
    }

    private function admin(): User
    {
        $this->seedRbac();

        $admin = User::factory()->create(['must_change_password' => false]);
        $admin->roles()->attach(Role::query()->where('name', User::SUPER_ADMIN_ROLE)->value('id'));

        return $admin;
    }

    public function test_the_chain_signs_in_order_and_the_gm_decides(): void
    {
        $this->send();
        $copies = [];

        foreach (['a1', 'a2', 'gm'] as $key) {
            $response = $this->decide($key)->assertOk()->assertJsonPath('message', 'تمت الموافقة.');

            $path = $this->document->fresh()->printed_path;
            $this->assertNotNull($path);
            $this->assertNotContains($path, $copies);
            Storage::disk('local')->assertExists($path);

            foreach ($copies as $previous) {
                Storage::disk('local')->assertMissing($previous);
            }

            $this->assertSame([$path], $this->printedCopies());
            $copies[] = $path;

            $response->assertJsonPath('data.has_printed_copy', true)->assertJsonPath('data.can.decide', false);
        }

        $document = $this->document->fresh();
        $this->assertSame(DocumentStatus::Approved, $document->status);
        $this->assertNotNull($document->decided_at);

        $marks = $document->marks;
        $this->assertCount(3, $marks);

        foreach (['a1', 'a2', 'gm'] as $index => $key) {
            /** @var DocumentMark $mark */
            $mark = $marks[$index];

            $this->assertSame($index + 1, $mark->position);
            $this->assertSame(MarkKind::ApprovalCard, $mark->kind);
            $this->assertSame($this->stepOf($key)->id, $mark->approval_step_id);
            $this->assertSame($this->people[$key]->id, $mark->employee_id);
            $this->assertSame($this->people[$key]->full_name, $mark->employee_name);
            $this->assertSame($this->units[$key]->id, $mark->unit_id);
            $this->assertSame($this->units[$key]->name, $mark->unit_name);
            $this->assertSame(app(UnitStamps::class)->current($this->units[$key])->id, $mark->stamp_id);
            $this->assertSame(app(Signatures::class)->current($this->people[$key])->id, $mark->signature_id);
            $this->assertSame(Confirmation::Biometric, $mark->confirmed_by);
            $this->assertSame($key === 'gm', $mark->decisive);
            $this->assertNotNull($mark->marked_at);
        }

        $review = 'يرجى مراجعة: '.self::TITLE;
        $this->assertToldOnce('a2', 'دورك للمراجعة', $review);
        $this->assertToldOnce('gm', 'جميع المدراء وافقوا — قرارك مطلوب', $review);
        $this->assertToldOnce('c', 'تمت الموافقة على مستندك', self::TITLE, NotificationEventCatalogue::DOCUMENT_APPROVED);
    }

    public function test_a_manager_s_rejection_is_recorded_and_the_document_goes_on(): void
    {
        $this->send();

        $this->decide('a1', 'reject', ['note' => '  يحتاج إلى تعديل  '])
            ->assertOk()
            ->assertJsonPath('message', 'تم الرفض.')
            ->assertJsonPath('data.status.value', DocumentStatus::Pending->value);

        $mark = $this->document->marks()->sole();
        $this->assertSame(MarkKind::RejectionCard, $mark->kind);
        $this->assertSame('يحتاج إلى تعديل', $mark->note);
        $this->assertFalse($mark->decisive);
        // A rejection card carries the stamp and the signature too (D22).
        $this->assertSame(app(UnitStamps::class)->current($this->units['a1'])->id, $mark->stamp_id);
        $this->assertSame(app(Signatures::class)->current($this->people['a1'])->id, $mark->signature_id);

        $this->assertToldOnce(
            'c',
            'تم رفض المستند من مدير',
            "رفض {$this->people['a1']->full_name} المستند «".self::TITLE.'»، وما زال المستند في مساره.',
            NotificationEventCatalogue::DOCUMENT_REJECTED_BY_MANAGER,
        );

        // A2 is now current.
        $this->assertSame(StepStatus::Rejected, $this->stepOf('a1')->status);
        $this->assertSame(2, $this->document->approval()->firstOrFail()->currentOrder());
        $this->decide('a2')->assertOk()->assertJsonPath('data.status.value', DocumentStatus::Pending->value);
        $this->decide('gm')->assertOk()->assertJsonPath('data.status.value', DocumentStatus::Approved->value);

        $this->assertSame(DocumentStatus::Approved, $this->document->fresh()->status);
        $this->assertSame(
            [MarkKind::RejectionCard, MarkKind::ApprovalCard, MarkKind::ApprovalCard],
            $this->document->marks()->get()->pluck('kind')->all(),
        );
        $this->assertToldOnce('c', 'تمت الموافقة على مستندك', self::TITLE);
    }

    public function test_the_gm_s_rejection_ends_it(): void
    {
        $this->send();
        $this->decide('a1')->assertOk();
        $this->decide('a2')->assertOk();

        $this->decide('gm', 'reject')
            ->assertOk()
            ->assertJsonPath('message', 'تم الرفض.')
            ->assertJsonPath('data.status.value', DocumentStatus::Rejected->value);

        $this->assertSame(DocumentStatus::Rejected, $this->document->fresh()->status);

        $last = $this->document->marks()->get()->last();
        $this->assertSame(MarkKind::RejectionCard, $last->kind);
        $this->assertTrue($last->decisive);

        $this->assertToldOnce('c', 'تم رفض مستندك', self::TITLE, NotificationEventCatalogue::DOCUMENT_REJECTED);
        // The decider's rejection ends it: it is not "still on its path".
        $this->assertCount(0, $this->notificationsOf($this->accounts['c'], 'تم رفض المستند من مدير'));
    }

    public function test_the_gm_s_own_document_is_approved_once_his_managers_sign(): void
    {
        $this->send('gm', ['a1', 'a2']);

        $this->decide('a1', 'reject')->assertOk()->assertJsonPath('data.status.value', DocumentStatus::Pending->value);
        $this->decide('a2')->assertOk()->assertJsonPath('data.status.value', DocumentStatus::Approved->value);

        // D30.2: no decider is left, so the rejection is recorded and changes nothing.
        $this->assertSame(DocumentStatus::Approved, $this->document->fresh()->status);
        $this->assertSame(
            [MarkKind::RejectionCard, MarkKind::ApprovalCard],
            $this->document->marks()->get()->pluck('kind')->all(),
        );
        $this->assertToldOnce('gm', 'تم رفض المستند من مدير', "رفض {$this->people['a1']->full_name} المستند «".self::TITLE.'»، وما زال المستند في مساره.');
        $this->assertToldOnce('gm', 'تمت الموافقة على مستندك', self::TITLE);
    }

    public function test_a_password_confirmation(): void
    {
        $this->send();

        $this->decide('a1', 'approve', ['confirmed_by' => Confirmation::Password->value, 'password' => 'not-the-password'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'كلمة المرور غير صحيحة.');

        $this->assertSame(StepStatus::Pending, $this->stepOf('a1')->status);
        $this->assertSame(0, DocumentMark::query()->count());

        $this->decide('a1', 'approve', ['confirmed_by' => Confirmation::Password->value, 'password' => 'password'])
            ->assertOk();

        $this->assertSame(Confirmation::Password, $this->document->marks()->sole()->confirmed_by);
    }

    public function test_the_request_is_validated(): void
    {
        $this->send();

        $this->decide('a1', 'maybe')
            ->assertStatus(422)->assertJsonPath('errors.decision.0', 'القرار يجب أن يكون موافقة أو رفضاً.');

        $this->decide('a1', 'approve', ['confirmed_by' => null])
            ->assertStatus(422)->assertJsonPath('errors.confirmed_by.0', 'تأكيد الهوية مطلوب.');

        $this->decide('a1', 'approve', ['confirmed_by' => 7])
            ->assertStatus(422)->assertJsonPath('errors.confirmed_by.0', 'تأكيد الهوية مطلوب.');

        $this->decide('a1', 'approve', ['confirmed_by' => Confirmation::Password->value])
            ->assertStatus(422)->assertJsonPath('errors.password.0', 'كلمة المرور مطلوبة للتأكيد.');

        $this->decide('a1', 'approve', ['note' => str_repeat('م', 501)])
            ->assertStatus(422)->assertJsonValidationErrors('note');

        $this->assertSame(StepStatus::Pending, $this->stepOf('a1')->status);
        $this->assertSame(0, DocumentMark::query()->count());
    }

    public function test_no_signature_asks_for_the_pad(): void
    {
        $this->send();
        app(Signatures::class)->delete($this->people['a1'], $this->accounts['gm'], 'توقيع غير واضح');

        $this->decide('a1')
            ->assertStatus(428)
            ->assertJsonPath('message', 'لم ترسم توقيعك بعد. ارسمه ثم أعد المحاولة.');

        $this->assertSame(StepStatus::Pending, $this->stepOf('a1')->status);
        $this->assertSame(0, DocumentMark::query()->count());
    }

    public function test_a_missing_stamp_holds_the_approval(): void
    {
        $admin = $this->admin();
        $this->send();

        $unit = $this->units['a1'];
        UnitStamp::query()->where('department_id', $unit->id)->update(['replaced_at' => now()]);

        $refusal = "لم يُرفع الختم الرسمي لوحدتك «{$unit->name}» بعد، ولا يمكن التوقيع قبل رفعه. تم إبلاغ مدير النظام.";
        $this->decide('a1')->assertStatus(422)->assertJsonPath('message', $refusal);
        $this->decide('a1')->assertStatus(422)->assertJsonPath('message', $refusal);

        $this->assertSame(StepStatus::Pending, $this->stepOf('a1')->status);
        $this->assertSame(0, DocumentMark::query()->count());

        // Told once per unit, not once per try.
        $told = $this->notificationsOf($admin, 'ختم وحدة غير مرفوع');
        $this->assertCount(1, $told);
        $this->assertSame("حاول {$this->people['a1']->full_name} التوقيع باسم «{$unit->name}» ولا ختم لها بعد.", $told[0]->body);
        $this->assertSame(NotificationEventCatalogue::DOCUMENT_STAMP_MISSING, $told[0]->type);
        $this->assertTrue(Cache::has("documents.stamp-missing.{$unit->id}"));

        // The inbox row says so before the manager taps (spec §7.4).
        Sanctum::actingAs($this->accounts['a1']);
        $this->getJson('/api/me/approvals')->assertOk()->assertJsonPath('data.0.details.stamp_missing', true);

        $stamp = app(UnitStamps::class)->replace($unit, UploadedFile::fake()->image('stamp.png', 300, 300), $admin);
        $this->assertFalse(Cache::has("documents.stamp-missing.{$unit->id}"));

        $this->getJson('/api/me/approvals')->assertOk()->assertJsonPath('data.0.details.stamp_missing', false);

        $this->decide('a1')->assertOk();
        $this->assertSame($stamp->id, $this->document->marks()->sole()->stamp_id);
        $this->assertSame(StepStatus::Approved, $this->stepOf('a1')->status);
    }

    public function test_a_manager_who_lost_their_unit_cannot_sign(): void
    {
        $this->send();
        $this->units['a1']->update(['manager_id' => null]);

        $this->decide('a1')
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا تدير وحدة تنظيمية حالياً، فلا يمكنك التوقيع على المستندات. يُرجى مراجعة مدير النظام لإعادة إسناد الخطوة.');

        $this->assertSame(StepStatus::Pending, $this->stepOf('a1')->status);
        $this->assertSame(0, DocumentMark::query()->count());
    }

    public function test_an_approver_whose_unit_is_deactivated_cannot_sign(): void
    {
        $this->send();
        $this->units['a1']->update(['is_active' => false]);

        $this->decide('a1')
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا تدير وحدة تنظيمية حالياً، فلا يمكنك التوقيع على المستندات. يُرجى مراجعة مدير النظام لإعادة إسناد الخطوة.');

        $this->assertSame(StepStatus::Pending, $this->stepOf('a1')->status);
        $this->assertSame(0, DocumentMark::query()->count());
    }

    public function test_the_gm_signs_though_the_root_is_inactive(): void
    {
        $this->send();
        $this->decide('a1')->assertOk();
        $this->decide('a2')->assertOk();
        $this->units['gm']->update(['is_active' => false]);

        $this->decide('gm')->assertOk()->assertJsonPath('message', 'تمت الموافقة.');

        $mark = DocumentMark::query()->where('employee_id', $this->people['gm']->id)->sole();
        $this->assertSame($this->units['gm']->id, $mark->unit_id);
        $this->assertSame(app(UnitStamps::class)->current($this->units['gm'])->id, $mark->stamp_id);
        $this->assertSame(DocumentStatus::Approved, $this->document->fresh()->status);
    }

    public function test_deciding_twice_records_one_mark(): void
    {
        $this->send();

        // Out of turn is the first check, before anything else is read.
        $this->decide('a2')->assertStatus(422)->assertJsonPath('message', 'ليس دورك في اعتماد هذا الطلب.');

        $this->decide('a1')->assertOk();
        $this->decide('a1')->assertStatus(422)->assertJsonPath('message', 'ليس دورك في اعتماد هذا الطلب.');

        $this->assertSame(1, $this->document->marks()->count());
        $this->assertCount(1, $this->printedCopies());
        $this->assertSame(StepStatus::Approved, $this->stepOf('a1')->status);
        $this->assertSame(StepStatus::Pending, $this->stepOf('a2')->status);
    }

    public function test_the_final_decision_twice_records_one_mark(): void
    {
        $this->send('c', ['a1']);
        $this->decide('a1')->assertOk();
        $this->decide('gm')->assertOk()->assertJsonPath('data.status.value', DocumentStatus::Approved->value);
        $printed = $this->document->fresh()->printed_path;

        // The approval is closed now: the retry is not the caller's turn.
        $this->decide('gm')->assertStatus(422)->assertJsonPath('message', 'ليس دورك في اعتماد هذا الطلب.');

        $this->assertSame(1, $this->document->marks()->where('employee_id', $this->people['gm']->id)->count());
        $this->assertSame(2, $this->document->marks()->count());
        $this->assertSame($printed, $this->document->fresh()->printed_path);
        $this->assertSame([$printed], $this->printedCopies());
    }

    public function test_a_print_failure_rolls_the_decision_back(): void
    {
        $this->send();
        $this->decide('a1')->assertOk();
        $printed = $this->document->fresh()->printed_path;

        $this->app->instance(DocumentPrinter::class, new class(app(UnitStamps::class), app(Signatures::class), app(PdfWorkspace::class), app(CardLayout::class)) extends DocumentPrinter
        {
            public function print(Document $document): string
            {
                throw new RuntimeException('The printer failed.');
            }
        });

        $this->decide('a2')->assertStatus(500);

        $this->assertSame(StepStatus::Pending, $this->stepOf('a2')->status);
        $this->assertSame(1, $this->document->marks()->count());
        $this->assertSame(DocumentStatus::Pending, $this->document->fresh()->status);
        $this->assertSame($printed, $this->document->fresh()->printed_path);
        $this->assertSame([$printed], $this->printedCopies());
        // The engine's word to the decider rolled back with the decision.
        $this->assertCount(0, $this->notificationsOf($this->accounts['gm'], 'جميع المدراء وافقوا — قرارك مطلوب'));
    }

    public function test_a_failed_write_of_the_printed_copy_rolls_the_decision_back(): void
    {
        $this->send();
        $this->decide('a1')->assertOk();
        $printed = $this->document->fresh()->printed_path;

        // The disk answers false to a write it cannot make (setUp's
        // throw => false); a directory standing where the new copy must go
        // makes one.
        $uuid = '7d2e4f60-1a3b-4c5d-8e9f-a0b1c2d3e4f5';
        Storage::disk('local')->makeDirectory("documents/{$this->document->folder}/printed/{$uuid}.pdf");
        Str::createUuidsUsing(static fn () => Uuid::fromString($uuid));

        try {
            $this->decide('a2')->assertStatus(500);
        } finally {
            Str::createUuidsNormally();
        }

        $this->assertSame(StepStatus::Pending, $this->stepOf('a2')->status);
        $this->assertSame(1, $this->document->marks()->count());
        $this->assertSame($printed, $this->document->fresh()->printed_path);
        Storage::disk('local')->assertExists($printed);
        $this->assertSame([$printed], $this->printedCopies());
    }

    public function test_a_failure_after_printing_deletes_the_new_copy_and_keeps_the_old(): void
    {
        $this->send();
        $this->decide('a1')->assertOk();
        $printed = $this->document->fresh()->printed_path;

        // The last thing the transaction does, after the new copy is recorded
        // and the old one's deletion is queued for the commit.
        $this->app->instance(NotificationService::class, new class extends NotificationService
        {
            public function sendToUser(User|int $user, string $title, ?string $body = null, ?string $type = null, ?Model $reference = null): int
            {
                if ($type === NotificationEventCatalogue::DOCUMENT_REJECTED_BY_MANAGER) {
                    throw new RuntimeException('The creator could not be told.');
                }

                return parent::sendToUser($user, $title, $body, $type, $reference);
            }
        });

        $this->decide('a2', 'reject')->assertStatus(500);

        $this->assertSame(StepStatus::Pending, $this->stepOf('a2')->status);
        $this->assertSame(1, $this->document->marks()->count());
        $this->assertSame($printed, $this->document->fresh()->printed_path);
        $this->assertSame([$printed], $this->printedCopies());
    }

    public function test_a_failure_after_the_commit_keeps_the_committed_copy(): void
    {
        $this->send();
        $this->decide('a1')->assertOk();
        $first = $this->document->fresh()->printed_path;

        // Laravel runs after-commit callbacks after the commit, still inside
        // transaction(): a push job that cannot be queued throws from there.
        $this->app->instance(NotificationService::class, new class extends NotificationService
        {
            public function sendToUser(User|int $user, string $title, ?string $body = null, ?string $type = null, ?Model $reference = null): int
            {
                $sent = parent::sendToUser($user, $title, $body, $type, $reference);

                if ($type === NotificationEventCatalogue::DOCUMENT_REJECTED_BY_MANAGER) {
                    DB::afterCommit(static fn () => throw new RuntimeException('The queue is down.'));
                }

                return $sent;
            }
        });

        $this->decide('a2', 'reject');

        // The decision is recorded, so the copy it names must be there.
        $document = $this->document->fresh();
        $this->assertSame(StepStatus::Rejected, $this->stepOf('a2')->status);
        $this->assertSame(2, $document->marks()->count());
        $this->assertNotSame($first, $document->printed_path);
        Storage::disk('local')->assertExists((string) $document->printed_path);
        $this->assertSame([$document->printed_path], $this->printedCopies());
    }

    public function test_deciding_a_deleted_document_answers_410_and_records_nothing(): void
    {
        $this->send('c', ['a1']);

        Sanctum::actingAs($this->accounts['c']);
        $this->deleteJson("/api/documents/{$this->document->id}")->assertOk();

        $this->decide('a1')->assertStatus(410)->assertJsonPath('message', 'حُذف هذا المستند.');

        $this->assertSame(0, DocumentMark::query()->count());
        $this->assertSame(StepStatus::Skipped, $this->stepOf('a1')->status);
    }
}
