<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Auth\Models\User;
use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Enums\Confirmation;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Enums\SignatureSource;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Services\Signatures;
use App\Modules\Documents\Services\UnitStamps;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Pdfs;
use Tests\TestCase;

/**
 * The documents needing attention (documents design §7.8): the pending ones
 * no one can move as they stand, each row saying why, for the system
 * administrator to reassign or close.
 */
class DocumentsNeedingAttentionTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Employee> */
    private array $people = [];

    /** @var array<string, User> */
    private array $accounts = [];

    /** @var array<string, Department> */
    private array $units = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->units['gm'] = Department::create(['name' => 'الإدارة العامة', 'level' => 1]);
        $this->units['c'] = Department::create(['name' => 'إدارة المالية', 'level' => 2, 'parent_id' => $this->units['gm']->id, 'sector' => 1]);
        $this->units['a1'] = Department::create(['name' => 'قسم الحسابات', 'level' => 3, 'parent_id' => $this->units['c']->id]);
        $this->units['a2'] = Department::create(['name' => 'قسم الميزانية', 'level' => 3, 'parent_id' => $this->units['c']->id]);

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
    private function send(string $title, array $approvers): Document
    {
        Sanctum::actingAs($this->accounts['c']);

        $this->post('/api/documents', [
            'title'     => $title,
            'file'      => UploadedFile::fake()->createWithContent('letter.pdf', Pdfs::plain()),
            'approvers' => array_map(fn (string $key): int => $this->people[$key]->id, $approvers),
        ], ['Accept' => 'application/json'])->assertCreated();

        return Document::query()->latest('id')->firstOrFail();
    }

    private function decide(Document $document, string $as): void
    {
        Sanctum::actingAs($this->accounts[$as]);

        $this->postJson("/api/documents/{$document->id}/decision", [
            'decision'     => 'approve',
            'confirmed_by' => Confirmation::Biometric->value,
        ])->assertOk();
    }

    private function attention(string $query = ''): TestResponse
    {
        $this->actingAsAdmin();

        return $this->getJson("/api/documents/attention{$query}")->assertOk();
    }

    private function stepOf(Document $document, string $key): ApprovalStep
    {
        return $document->approval()->firstOrFail()->steps()->where('approver_id', $this->people[$key]->id)->firstOrFail();
    }

    private function newGeneralManager(): Employee
    {
        $employee = Employee::factory()->create();
        $this->units['gm']->update(['manager_id' => $employee->id]);

        return $employee;
    }

    /**
     * @return array<string, mixed>
     */
    private function expected(string $reason, ApprovalStep $step, string $approver): array
    {
        return [
            'reason'   => $reason,
            'step_id'  => $step->id,
            'approver' => ['id' => $this->people[$approver]->id, 'full_name' => $this->people[$approver]->full_name],
        ];
    }

    public function test_a_vacant_general_manager_post_lists_every_document_with_a_decisive_step(): void
    {
        $document = $this->send('بانتظار مدير عام', ['a1']);
        $this->units['gm']->update(['manager_id' => null]);

        // No one is المدير العام, so whoever holds the decisive step is "former".
        $this->attention()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $document->id)
            ->assertJsonPath('data.0.attention', $this->expected('former_general_manager', $this->stepOf($document, 'gm'), 'gm'));
    }

    public function test_a_document_waiting_on_a_former_general_manager_is_listed(): void
    {
        $older = $this->send('أقدم', ['a1', 'a2']);
        $newer = $this->send('أحدث', ['a2']);
        $this->newGeneralManager();

        $response = $this->attention();
        $this->assertSame([$newer->id, $older->id], array_column($response->json('data'), 'id'));

        // Each row is the document as every endpoint shows it, and why.
        $response
            ->assertJsonPath('data.0.title', 'أحدث')
            ->assertJsonPath('data.0.status.value', DocumentStatus::Pending->value)
            ->assertJsonPath('data.0.creator.id', $this->people['c']->id)
            ->assertJsonCount(2, 'data.0.steps')
            ->assertJsonPath('data.0.attention', $this->expected('former_general_manager', $this->stepOf($newer, 'gm'), 'gm'))
            ->assertJsonPath('data.1.attention', $this->expected('former_general_manager', $this->stepOf($older, 'gm'), 'gm'));

        // Paged like every list.
        $this->attention('?per_page=1&page=2')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $older->id);
    }

    public function test_a_document_whose_current_approver_s_unit_was_deactivated_is_listed(): void
    {
        $waitingOnA1 = $this->send('بانتظار الحسابات', ['a1', 'a2']);
        $a1Later = $this->send('الحسابات لاحقاً', ['a2', 'a1']);
        $this->units['a1']->update(['is_active' => false]);

        $response = $this->attention();

        // A1 is not yet asked on the second: it can still move.
        $this->assertSame([$waitingOnA1->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('data.0.attention', $this->expected('approver_manages_no_unit', $this->stepOf($waitingOnA1, 'a1'), 'a1'));

        // Once A2 signs the second, A1 is current on it too.
        $this->decide($a1Later, 'a2');
        $this->assertSame([$a1Later->id, $waitingOnA1->id], array_column($this->attention()->json('data'), 'id'));
    }

    public function test_a_document_waiting_on_a_deleted_approver_is_listed(): void
    {
        $document = $this->send('بانتظار محذوف', ['a1']);

        // HR's delete is soft: the unit still names them as its manager.
        $this->people['a1']->delete();

        $this->attention()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $document->id)
            ->assertJsonPath('data.0.attention', $this->expected('approver_manages_no_unit', $this->stepOf($document, 'a1'), 'a1'));
    }

    public function test_a_document_waiting_on_a_deleted_general_manager_is_listed(): void
    {
        $document = $this->send('بانتظار مدير عام محذوف', ['a1']);
        $this->decide($document, 'a1');

        $this->people['gm']->delete();

        // The root's manager no longer reads: the post is vacant.
        $this->attention()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $document->id)
            ->assertJsonPath('data.0.attention', $this->expected('former_general_manager', $this->stepOf($document, 'gm'), 'gm'));
    }

    public function test_a_document_matching_both_reasons_is_listed_once(): void
    {
        $document = $this->send('بانتظار القرار', ['a1']);
        $this->decide($document, 'a1');

        // The former المدير العام manages no unit now, and holds the current step.
        $this->newGeneralManager();

        $this->attention()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.attention', $this->expected('former_general_manager', $this->stepOf($document, 'gm'), 'gm'));
    }

    public function test_a_healthy_pending_document_is_absent(): void
    {
        $this->send('سليم', ['a1', 'a2']);

        $approved = $this->send('معتمد', ['a2']);
        $this->decide($approved, 'a2');
        $this->decide($approved, 'gm');

        $this->attention()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);

        // The decided one stays out though its signer's unit goes inactive.
        $this->units['a2']->update(['is_active' => false]);
        $this->assertSame(0, $this->attention()->json('meta.total'));
    }

    public function test_an_outsider_gets_403(): void
    {
        $this->send('سري', ['a1']);
        $this->newGeneralManager();

        $this->actingAsUserWithPermissions(['documents.view']);
        $this->getJson('/api/documents/attention')->assertForbidden();

        Sanctum::actingAs($this->accounts['c']);
        $this->getJson('/api/documents/attention')->assertForbidden();
    }
}
