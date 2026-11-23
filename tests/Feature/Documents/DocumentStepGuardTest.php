<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Approvals\Enums\ApprovalStatus;
use App\Modules\Approvals\Enums\RejectionPolicy;
use App\Modules\Approvals\Enums\StepStatus;
use App\Modules\Approvals\Models\Approval;
use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Approvals\Services\ApprovalService;
use App\Modules\Approvals\Support\ApprovalRoute;
use App\Modules\Approvals\Support\StepDefinition;
use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Auth\Models\User;
use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Services\DecisionGate;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Approvals\Fakes\FakeApprovable;
use Tests\Support\Pdfs;
use Tests\TestCase;

/**
 * The guard on a document's approval steps (documents design §7.3): the
 * general approvals endpoints neither decide them nor move them freely.
 */
class DocumentStepGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Employee> */
    private array $people = [];

    /** @var array<string, User> */
    private array $accounts = [];

    private Department $root;

    private Approval $approval;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->root = Department::create(['name' => 'الإدارة العامة', 'level' => 1]);
        $division = Department::create(['name' => 'إدارة المالية', 'level' => 2, 'parent_id' => $this->root->id, 'sector' => 1]);

        $this->manage('gm', $this->root);
        $this->manage('c', $division);

        foreach (['a1' => 'قسم الحسابات', 'a2' => 'قسم الميزانية', 'a3' => 'قسم المشتريات'] as $key => $name) {
            $this->manage($key, Department::create(['name' => $name, 'level' => 3, 'parent_id' => $division->id]));
        }

        $this->person('plain');

        Sanctum::actingAs($this->accounts['c']);
        $this->post('/api/documents', [
            'title'     => 'تعميم داخلي',
            'file'      => UploadedFile::fake()->createWithContent('letter.pdf', Pdfs::plain()),
            'approvers' => [$this->people['a1']->id, $this->people['a2']->id],
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->approval = Document::query()->firstOrFail()->approval;
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

    private function step(int $order): ApprovalStep
    {
        return $this->approval->steps()->where('step_order', $order)->sole();
    }

    private function reassign(ApprovalStep $step, Employee $to): TestResponse
    {
        return $this->patchJson(
            "/api/approvals/{$this->approval->id}/steps/{$step->id}/assignee",
            ['employee_id' => $to->id, 'reason' => 'المدير في إجازة'],
        );
    }

    public function test_the_general_approve_endpoint_refuses_a_document_step(): void
    {
        Sanctum::actingAs($this->accounts['a1']);
        $step = $this->step(1);

        $this->postJson("/api/approvals/{$this->approval->id}/steps/{$step->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'يُتَّخذ القرار في المستندات من شاشة المستند.');

        $this->assertSame(StepStatus::Pending, $step->fresh()->status);
    }

    public function test_the_gate_opens_a_step_only_while_its_work_runs(): void
    {
        $gate = app(DecisionGate::class);
        $step = $this->step(1);

        $this->assertFalse($gate->isOpen($step->id));

        $result = $gate->through($step->id, function () use ($gate, $step) {
            $this->assertTrue($gate->isOpen($step->id));
            $gate->through($step->id, fn () => null);
            $this->assertTrue($gate->isOpen($step->id), 'an inner call closes only what it opened');

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertFalse($gate->isOpen($step->id));

        try {
            $gate->through($step->id, fn () => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }
        $this->assertFalse($gate->isOpen($step->id));
    }

    public function test_an_open_step_can_be_decided_by_the_engine(): void
    {
        $step = $this->step(1);

        app(DecisionGate::class)->through($step->id, fn () => $step->update(['status' => StepStatus::Approved]));

        $this->assertSame(StepStatus::Approved, $step->fresh()->status);
    }

    public function test_only_the_system_administrator_reassigns_a_document_step(): void
    {
        $step = $this->step(1);

        $this->actingAsScopedUser(['approvals.reassign', 'documents.view'], ScopeType::Company);
        $this->reassign($step, $this->people['a3'])
            ->assertForbidden()
            ->assertJsonPath('message', 'إعادة إسناد خطوات المستندات لمدير النظام فقط.');
        $this->assertSame($this->people['a1']->id, $step->fresh()->approver_id);

        $this->actingAsAdmin();
        $this->reassign($step, $this->people['plain'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن الإسناد إلا إلى مدير وحدة تنظيمية.');
        $this->assertSame($this->people['a1']->id, $step->fresh()->approver_id);

        $this->reassign($step, $this->people['a3'])->assertOk();
        $this->assertSame($this->people['a3']->id, $step->fresh()->approver_id);
    }

    public function test_a_reassigner_who_may_not_read_documents_learns_nothing_of_the_chain(): void
    {
        $this->actingAsScopedUser(['approvals.reassign'], ScopeType::Company);

        // Engine refusals (the requester; a decided step) would describe the chain.
        $this->reassign($this->step(1), $this->people['c'])->assertForbidden();

        $this->step(1)->update(['status' => StepStatus::Skipped]);
        $this->reassign($this->step(1), $this->people['a3'])->assertForbidden();

        $this->approval->update(['status' => ApprovalStatus::Cancelled]);
        $this->reassign($this->step(2), $this->people['a3'])->assertForbidden();

        $this->assertSame($this->people['a1']->id, $this->step(1)->approver_id);
    }

    public function test_a_step_cannot_go_to_a_manager_of_an_inactive_unit(): void
    {
        Department::query()->where('manager_id', $this->people['a3']->id)->update(['is_active' => false]);
        $step = $this->step(1);

        $this->actingAsAdmin();
        $this->reassign($step, $this->people['a3'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن الإسناد إلا إلى مدير وحدة تنظيمية.');
        $this->assertSame($this->people['a1']->id, $step->fresh()->approver_id);
    }

    public function test_the_decider_s_step_goes_only_to_the_current_general_manager(): void
    {
        $decisive = $this->step(3);
        $this->assertTrue($decisive->decisive);

        $newGm = $this->person('gm2');
        $this->root->update(['manager_id' => $newGm->id]);

        $this->actingAsAdmin();
        $this->reassign($decisive, $this->people['a3'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'خطوة القرار الأخير لا تُسند إلا إلى المدير العام الحالي.');
        $this->assertSame($this->people['gm']->id, $decisive->fresh()->approver_id);

        $this->reassign($decisive, $newGm)->assertOk();
        $this->assertSame($newGm->id, $decisive->fresh()->approver_id);
    }

    public function test_the_decider_s_step_still_reaches_the_gm_though_the_root_is_inactive(): void
    {
        $newGm = $this->person('gm2');
        $this->root->update(['manager_id' => $newGm->id, 'is_active' => false]);

        $this->actingAsAdmin();
        $this->reassign($this->step(3), $newGm)->assertOk();
        $this->assertSame($newGm->id, $this->step(3)->approver_id);
    }

    public function test_a_named_step_never_goes_to_the_general_manager(): void
    {
        // A new المدير العام holds no step yet, so the engine would let him
        // take A1's; then the decider's step could never reach him (spec §5).
        $newGm = $this->person('gm2');
        $this->root->update(['manager_id' => $newGm->id]);
        $step = $this->step(1);

        $this->actingAsAdmin();
        $this->reassign($step, $newGm)
            ->assertStatus(422)
            ->assertJsonPath('message', 'المدير العام لا يُسند إليه إلا خطوة القرار الأخير.');
        $this->assertSame($this->people['a1']->id, $step->fresh()->approver_id);

        $this->reassign($this->step(3), $newGm)->assertOk();
        $this->assertSame($newGm->id, $this->step(3)->approver_id);
    }

    public function test_leave_steps_are_untouched(): void
    {
        Schema::create('fake_approvables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id');
            $table->string('outcome')->nullable();
            $table->unsignedInteger('decided_count')->default(0);
            $table->string('refusal')->nullable();
            $table->timestamps();
        });
        Relation::morphMap([FakeApprovable::MORPH_ALIAS => FakeApprovable::class]);

        try {
            $other = FakeApprovable::create(['employee_id' => $this->people['c']->id]);
            $approval = app(ApprovalService::class)->submit($other, ApprovalRoute::sequential(
                RejectionPolicy::Ends,
                StepDefinition::namedEmployee($this->people['a1']->id),
                StepDefinition::namedEmployee($this->people['a2']->id),
            ));

            $this->actingAsScopedUser(['approvals.reassign'], ScopeType::Company);
            $this->patchJson(
                "/api/approvals/{$approval->id}/steps/{$approval->steps()->where('step_order', 2)->value('id')}/assignee",
                ['employee_id' => $this->people['plain']->id, 'reason' => 'المدير في إجازة'],
            )->assertOk();

            Sanctum::actingAs($this->accounts['a1']);
            $first = $approval->steps()->where('step_order', 1)->sole();
            $this->postJson("/api/approvals/{$approval->id}/steps/{$first->id}/approve")->assertOk();

            $this->assertSame(StepStatus::Approved, $first->fresh()->status);
        } finally {
            $map = Relation::morphMap();
            unset($map[FakeApprovable::MORPH_ALIAS]);
            Relation::morphMap($map, false);
        }
    }

    public function test_a_withdrawal_closes_steps_as_skipped(): void
    {
        Sanctum::actingAs($this->accounts['c']);

        $this->postJson("/api/approvals/{$this->approval->id}/withdraw")->assertOk();

        $this->assertSame(ApprovalStatus::Cancelled, $this->approval->fresh()->status);
        $this->assertSame(
            [StepStatus::Skipped],
            $this->approval->steps()->get()->pluck('status')->unique()->values()->all(),
        );
    }
}
