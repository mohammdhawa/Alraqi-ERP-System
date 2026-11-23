<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Approvals\Enums\ApprovalStatus;
use App\Modules\Approvals\Enums\SkipReason;
use App\Modules\Approvals\Enums\StepStatus;
use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Auth\Models\Notification;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Enums\Confirmation;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Enums\SignatureSource;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Services\Signatures;
use App\Modules\Documents\Services\UnitStamps;
use App\Modules\HR\Models\Employee;
use App\Shared\Support\NotificationEventCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Pdfs;
use Tests\TestCase;

/**
 * Closing a stuck document as «لم يُستكمل» (documents design §7.8): the system
 * administrator's alone, on a pending document, with a reason. What was
 * signed stays on record; the creator is told and sends it again.
 */
class DocumentClosingTest extends TestCase
{
    use RefreshDatabase;

    private const TITLE = 'تعميم داخلي';

    private const REASON = 'المدير العام الجديد سبق أن وقّع عليه';

    /** @var array<string, Employee> */
    private array $people = [];

    /** @var array<string, User> */
    private array $accounts = [];

    /** @var array<string, Department> */
    private array $units = [];

    private ?User $admin = null;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

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
    private function sendResponse(array $approvers = ['a1', 'a2'], ?int $importNumber = 77): TestResponse
    {
        Sanctum::actingAs($this->accounts['c']);

        return $this->post('/api/documents', array_filter([
            'title'         => self::TITLE,
            'file'          => UploadedFile::fake()->createWithContent('letter.pdf', Pdfs::plain()),
            'approvers'     => array_map(fn (string $key): int => $this->people[$key]->id, $approvers),
            'import_number' => $importNumber,
        ], static fn (mixed $value): bool => $value !== null), ['Accept' => 'application/json']);
    }

    /**
     * @param  array<int, string>  $approvers
     */
    private function send(array $approvers = ['a1', 'a2'], ?int $importNumber = 77): Document
    {
        $this->sendResponse($approvers, $importNumber)->assertCreated();

        return Document::query()->latest('id')->firstOrFail();
    }

    private function decide(Document $document, string $as): TestResponse
    {
        Sanctum::actingAs($this->accounts[$as]);

        return $this->postJson("/api/documents/{$document->id}/decision", [
            'decision'     => 'approve',
            'confirmed_by' => Confirmation::Biometric->value,
        ]);
    }

    private function admin(): User
    {
        if ($this->admin === null) {
            $this->seedRbac();

            $this->admin = User::factory()->create(['must_change_password' => false]);
            $this->admin->roles()->attach(Role::query()->where('name', User::SUPER_ADMIN_ROLE)->value('id'));
        }

        Sanctum::actingAs($this->admin);

        return $this->admin;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function close(Document $document, array $body = ['reason' => self::REASON]): TestResponse
    {
        return $this->postJson("/api/documents/{$document->id}/close", $body);
    }

    private function stepOf(Document $document, string $key): ApprovalStep
    {
        return $document->approval()->firstOrFail()->steps()->where('approver_id', $this->people[$key]->id)->firstOrFail();
    }

    public function test_the_administrator_closes_a_signed_document_as_not_completed(): void
    {
        $document = $this->send();
        $this->decide($document, 'a1')->assertOk();
        $printed = $document->fresh()->printed_path;
        $this->assertNotNull($printed);

        $admin = $this->admin();
        $this->close($document, ['reason' => '  '.self::REASON.'  '])
            ->assertOk()
            ->assertJsonPath('message', 'أُغلق المستند: لم يُستكمل.')
            ->assertJsonPath('data.status.value', DocumentStatus::NotCompleted->value)
            ->assertJsonPath('data.status.label', 'لم يُستكمل')
            ->assertJsonPath('data.closed_reason', self::REASON)
            ->assertJsonPath('data.can.delete', false);

        $document = Document::query()->findOrFail($document->id);
        $this->assertSame(DocumentStatus::NotCompleted, $document->status);
        $this->assertSame(self::REASON, $document->closed_reason);
        $this->assertSame($admin->id, $document->closed_by);
        $this->assertNotNull($document->decided_at);

        // What was signed stays on record: the card, the printed copy, the number.
        $this->assertSame(1, $document->marks()->count());
        $this->assertSame($printed, $document->printed_path);
        Storage::disk('local')->assertExists($printed);
        $this->assertSame(77, $document->import_number);

        $approval = $document->approval()->firstOrFail();
        $this->assertSame(ApprovalStatus::Cancelled, $approval->status);
        $this->assertSame(StepStatus::Approved, $this->stepOf($document, 'a1')->status);

        foreach (['a2', 'gm'] as $key) {
            $step = $this->stepOf($document, $key);
            $this->assertSame(StepStatus::Skipped, $step->status, "{$key}'s step should be closed.");
            $this->assertSame(SkipReason::ClosedByAdministrator, $step->skip_reason);
        }

        Sanctum::actingAs($this->accounts['a2']);
        $this->getJson('/api/me/approvals')->assertOk()->assertJsonCount(0, 'data');

        $told = Notification::query()->where('user_id', $this->accounts['c']->id)->where('title', 'لم يُستكمل مستندك')->get();
        $this->assertCount(1, $told);
        $this->assertSame('أغلق مدير النظام المستند «'.self::TITLE.'»: '.self::REASON.'. يمكنك إرساله من جديد.', $told[0]->body);
        $this->assertSame(NotificationEventCatalogue::DOCUMENT_NOT_COMPLETED, $told[0]->type);
        $this->assertSame('لم يُستكمل المستند', NotificationEventCatalogue::labelOf($told[0]->type));
        $this->assertSame('document', $told[0]->reference_type);
        $this->assertSame($document->id, (int) $told[0]->reference_id);

        // Still on record for the creator, in the list and on its own.
        Sanctum::actingAs($this->accounts['c']);
        $this->assertSame([$document->id], array_column($this->getJson('/api/documents?box=outgoing')->assertOk()->json('data'), 'id'));
        $this->assertSame(
            [$document->id],
            array_column($this->getJson('/api/documents?box=outgoing&status='.DocumentStatus::NotCompleted->value)->assertOk()->json('data'), 'id'),
        );
        $this->getJson("/api/documents/{$document->id}")
            ->assertOk()
            ->assertJsonPath('data.status.value', DocumentStatus::NotCompleted->value)
            ->assertJsonPath('data.closed_reason', self::REASON);
    }

    public function test_the_inbox_row_carries_the_document_s_own_status(): void
    {
        $document = $this->send();
        $this->decide($document, 'a1')->assertOk();

        Sanctum::actingAs($this->accounts['a2']);
        $this->getJson('/api/me/approvals')->assertOk()
            ->assertJsonPath('data.0.details.document_status', DocumentStatus::Pending->value)
            ->assertJsonPath('data.0.details.document_status_label', 'قيد الاعتماد');

        $this->admin();
        $this->close($document)->assertOk();

        // The engine's label is unchanged; the document's own status rides beside it.
        Sanctum::actingAs($this->accounts['a1']);
        $this->getJson('/api/me/approvals?status=decided')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.approval_status_label', 'مسحوب')
            ->assertJsonPath('data.0.details.document_status', DocumentStatus::NotCompleted->value)
            ->assertJsonPath('data.0.details.document_status_label', 'لم يُستكمل');
    }

    public function test_the_promotion_dead_end_ends_cleanly(): void
    {
        $document = $this->send();
        $this->decide($document, 'a1')->assertOk();

        // A1 becomes المدير العام: one unit per manager, so his own goes first.
        $this->units['a1']->update(['manager_id' => null]);
        $this->units['gm']->update(['manager_id' => $this->people['a1']->id]);

        // He signed a named step, so the engine cannot give him the decider's.
        $decisive = $this->stepOf($document, 'gm');
        $this->admin();
        $this->patchJson(
            "/api/approvals/{$decisive->approval_id}/steps/{$decisive->id}/assignee",
            ['employee_id' => $this->people['a1']->id, 'reason' => 'ترقية إلى المدير العام'],
        )
            ->assertStatus(422)
            ->assertJsonPath('message', 'هذا الموظف يحمل خطوة أخرى في مسار اعتماد هذا الطلب.');
        $this->assertSame($this->people['gm']->id, $decisive->fresh()->approver_id);

        $this->close($document)->assertOk()->assertJsonPath('message', 'أُغلق المستند: لم يُستكمل.');

        $this->assertSame(DocumentStatus::NotCompleted, $document->fresh()->status);
        $this->assertSame(ApprovalStatus::Cancelled, $document->approval()->firstOrFail()->status);
    }

    public function test_only_the_system_administrator_closes(): void
    {
        $document = $this->send();

        $this->actingAsUserWithPermissions(['documents.view']);
        $this->close($document)->assertForbidden();

        Sanctum::actingAs($this->accounts['c']);
        $this->close($document)->assertForbidden();

        $this->actingAsUserWithPermissions(Permission::query()->pluck('name')->all());
        $this->close($document)->assertForbidden();

        // An ability nobody passes but the super-admin bypass; never a row.
        $this->assertTrue(Gate::has('documents.close'));
        $this->assertFalse(Permission::query()->where('name', 'documents.close')->exists());

        $this->assertSame(DocumentStatus::Pending, $document->fresh()->status);
        $this->assertSame(ApprovalStatus::Pending, $document->approval()->firstOrFail()->status);
    }

    public function test_only_a_pending_document_closes(): void
    {
        $approved = $this->send(['a1']);
        $this->decide($approved, 'a1')->assertOk();
        $this->decide($approved, 'gm')->assertOk();

        $this->admin();
        $this->close($approved)->assertStatus(422)->assertJsonPath('message', 'لا يُغلق إلا مستند قيد الاعتماد.');
        $this->assertSame(DocumentStatus::Approved, $approved->fresh()->status);
        $this->assertNull($approved->fresh()->closed_reason);

        $closed = $this->send(['a1'], 78);
        $this->admin();
        $this->close($closed)->assertOk();
        $this->close($closed, ['reason' => 'مرة ثانية'])->assertStatus(422)->assertJsonPath('message', 'لا يُغلق إلا مستند قيد الاعتماد.');
        $this->assertSame(self::REASON, $closed->fresh()->closed_reason);

        $deleted = $this->send(['a1'], 79);
        Sanctum::actingAs($this->accounts['c']);
        $this->deleteJson("/api/documents/{$deleted->id}")->assertOk();
        $this->admin();
        $this->close($deleted)->assertStatus(410)->assertJsonPath('message', 'حُذف هذا المستند.');
    }

    public function test_a_reason_is_required(): void
    {
        $document = $this->send();
        $this->admin();

        foreach ([[], ['reason' => null], ['reason' => '  مم  '], ['reason' => str_repeat('م', 501)], ['reason' => ['مصفوفة']]] as $body) {
            $this->close($document, $body)
                ->assertStatus(422)
                ->assertJsonPath('errors.reason.0', 'سبب الإغلاق مطلوب، من 3 إلى 500 حرف.');
        }

        $this->assertSame(DocumentStatus::Pending, $document->fresh()->status);

        $this->close($document, ['reason' => str_repeat('م', 500)])->assertOk();
    }

    public function test_the_creator_sends_again_with_the_same_incoming_number(): void
    {
        $document = $this->send();

        $this->sendResponse()
            ->assertStatus(422)
            ->assertJsonPath('message', 'رقم الوارد 77 مستخدم في سجل هذه الإدارة.');

        $this->admin();
        $this->close($document)->assertOk();

        $this->sendResponse()->assertCreated()->assertJsonPath('data.import_number', 77);

        $this->assertSame(2, Document::query()->where('import_number', 77)->count());
        $this->assertSame(1, Document::query()->where('import_number', 77)->where('status', DocumentStatus::Pending->value)->count());
    }
}
