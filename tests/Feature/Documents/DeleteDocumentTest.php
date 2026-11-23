<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Approvals\Enums\ApprovalStatus;
use App\Modules\Approvals\Enums\SkipReason;
use App\Modules\Approvals\Models\Approval;
use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\User;
use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Enums\Confirmation;
use App\Modules\Documents\Enums\SignatureSource;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Services\Signatures;
use App\Modules\Documents\Services\UnitStamps;
use App\Modules\HR\Models\Employee;
use App\Shared\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Pdfs;
use Tests\TestCase;

/**
 * Deleting a document (documents design §7.6): the creator or the
 * administrator, before the first signature, through the engine's withdrawal.
 */
class DeleteDocumentTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Employee> */
    private array $people = [];

    /** @var array<string, User> */
    private array $accounts = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $units = [
            'gm' => Department::create(['name' => 'الإدارة العامة', 'level' => 1]),
        ];
        $units['c'] = Department::create(['name' => 'إدارة المالية', 'level' => 2, 'parent_id' => $units['gm']->id, 'sector' => 1]);
        $units['a1'] = Department::create(['name' => 'قسم الحسابات', 'level' => 3, 'parent_id' => $units['c']->id]);
        $units['a2'] = Department::create(['name' => 'قسم الميزانية', 'level' => 3, 'parent_id' => $units['c']->id]);

        foreach ($units as $key => $unit) {
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
    private function send(array $approvers, string $as = 'c'): Document
    {
        Sanctum::actingAs($this->accounts[$as]);

        $this->post('/api/documents', [
            'title'     => 'للحذف',
            'file'      => UploadedFile::fake()->createWithContent('letter.pdf', Pdfs::plain()),
            'approvers' => array_map(fn (string $key): int => $this->people[$key]->id, $approvers),
        ], ['Accept' => 'application/json'])->assertCreated();

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

    /**
     * @return array<int, int>
     */
    private function inboxSubjects(): array
    {
        $rows = $this->getJson('/api/me/approvals')->assertOk()->json('data');

        return array_map(static fn (array $row): int => (int) $row['subject']['id'], $rows);
    }

    private function assertDeletedThroughTheEngine(Document $document): Approval
    {
        $approval = Approval::query()->where('approvable_id', $document->id)->firstOrFail();

        $this->assertSame(ApprovalStatus::Cancelled, $approval->status);
        $this->assertNull($approval->deleted_at, 'the approval is closed, never trashed');

        $steps = ApprovalStep::query()->where('approval_id', $approval->id)->get();
        $this->assertNotEmpty($steps);

        foreach ($steps as $step) {
            $this->assertSame(SkipReason::ClosedByCancellation, $step->skip_reason);
            $this->assertNull($step->deleted_at);
        }

        return $approval;
    }

    public function test_the_creator_deletes_before_the_first_signature(): void
    {
        $document = $this->send(['a1', 'a2']);

        Sanctum::actingAs($this->accounts['a1']);
        $this->assertContains($document->id, $this->inboxSubjects(), 'it is in A1\'s inbox first');

        Sanctum::actingAs($this->accounts['c']);
        $this->deleteJson("/api/documents/{$document->id}")
            ->assertOk()
            ->assertJsonPath('message', 'تم حذف المستند.');

        $trashed = Document::withTrashed()->findOrFail($document->id);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame($this->accounts['c']->id, $trashed->deleted_by);
        $this->assertDeletedThroughTheEngine($document);

        $this->assertSame(0, Document::query()->where('id', $document->id)->count());

        Sanctum::actingAs($this->accounts['c']);
        $this->getJson('/api/documents?box=outgoing')->assertOk()->assertJsonCount(0, 'data');
        Sanctum::actingAs($this->accounts['a1']);
        $this->getJson('/api/documents?box=incoming')->assertOk()->assertJsonCount(0, 'data');

        $this->assertNotContains($document->id, $this->inboxSubjects());
    }

    public function test_the_system_administrator_deletes_any_unsigned_document(): void
    {
        $document = $this->send(['a1']);

        $admin = $this->actingAsAdmin();
        $this->deleteJson("/api/documents/{$document->id}")
            ->assertOk()
            ->assertJsonPath('message', 'تم حذف المستند.');

        $trashed = Document::withTrashed()->findOrFail($document->id);
        $this->assertSame($admin->id, $trashed->deleted_by);
        $this->assertDeletedThroughTheEngine($document);

        $this->assertTrue(
            AuditLog::query()
                ->where('auditable_type', $document->getMorphClass())
                ->where('auditable_id', $document->id)
                ->where('event', 'deleted')
                ->where('user_id', $admin->id)
                ->exists(),
            'the audit trail records the administrator',
        );
    }

    public function test_a_signed_document_cannot_be_deleted(): void
    {
        $document = $this->send(['a1', 'a2']);
        $this->decide($document, 'a1')->assertOk();

        Sanctum::actingAs($this->accounts['c']);
        $this->deleteJson("/api/documents/{$document->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'لا يمكن حذف مستند بعد أول توقيع عليه.');

        $this->actingAsAdmin();
        $this->deleteJson("/api/documents/{$document->id}")->assertUnprocessable();

        $this->assertNull(Document::withTrashed()->findOrFail($document->id)->deleted_at);
        $this->assertSame(ApprovalStatus::Pending, Approval::query()->where('approvable_id', $document->id)->firstOrFail()->status);
        $this->assertFalse(
            AuditLog::query()->where('auditable_type', $document->getMorphClass())->where('auditable_id', $document->id)->where('event', 'deleted')->exists(),
        );
    }

    public function test_only_the_creator_or_the_administrator(): void
    {
        $document = $this->send(['a1']);

        Sanctum::actingAs($this->accounts['a1']);
        $this->deleteJson("/api/documents/{$document->id}")->assertForbidden();

        $this->actingAsUserWithPermissions(['documents.view']);
        $this->deleteJson("/api/documents/{$document->id}")->assertForbidden();

        $this->assertNull(Document::withTrashed()->findOrFail($document->id)->deleted_at);
    }

    public function test_the_general_withdraw_is_a_deletion(): void
    {
        $document = $this->send(['a1']);
        $approval = Approval::query()->where('approvable_id', $document->id)->firstOrFail();

        Sanctum::actingAs($this->accounts['c']);
        $this->postJson("/api/approvals/{$approval->id}/withdraw")->assertOk();

        $trashed = Document::withTrashed()->findOrFail($document->id);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame($this->accounts['c']->id, $trashed->deleted_by);
        $this->assertDeletedThroughTheEngine($document);
    }

    public function test_a_deleted_document_answers_410_to_its_parties(): void
    {
        $document = $this->send(['a1']);

        Sanctum::actingAs($this->accounts['c']);
        $this->deleteJson("/api/documents/{$document->id}")->assertOk();

        // An approver who is not the creator, opening it from an earlier
        // notification: the trashed document still binds and the policy still
        // admits him through the approval's steps.
        Sanctum::actingAs($this->accounts['a1']);
        $this->getJson("/api/documents/{$document->id}")->assertStatus(410)->assertJsonPath('message', 'حُذف هذا المستند.');

        Sanctum::actingAs($this->accounts['c']);
        $this->getJson("/api/documents/{$document->id}")->assertStatus(410);
        $this->deleteJson("/api/documents/{$document->id}")->assertStatus(410)->assertJsonPath('message', 'حُذف هذا المستند.');

        $names = Permission::query()->where('name', '!=', 'documents.view')->pluck('name')->all();
        $this->actingAsUserWithPermissions($names);
        $this->getJson("/api/documents/{$document->id}")->assertForbidden();
    }
}
