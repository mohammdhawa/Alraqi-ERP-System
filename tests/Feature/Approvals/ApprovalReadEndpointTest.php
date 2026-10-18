<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Modules\Approvals\Enums\ApprovalStatus;
use App\Modules\Approvals\Enums\Decision;
use App\Modules\Approvals\Enums\SkipReason;
use App\Modules\Approvals\Enums\StepResolver;
use App\Modules\Approvals\Enums\StepStatus;
use App\Modules\Approvals\Models\Approval;
use App\Modules\Auth\Enums\ScopeType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Approvals\Concerns\ApprovalFixtures;
use Tests\TestCase;

/**
 * GET /api/approvals/{approval} — the live request screen (design §7).
 *
 * Read fresh on every call: every step, its approver, who acted and when,
 * the note, the skips with their reasons, the reassignments, and what the
 * caller may do now. Readers: the requester, anyone on the chain, and
 * `approvals.view` within reach of the requester's CURRENT unit (§9 Q5 = A,
 * D6).
 */
class ApprovalReadEndpointTest extends TestCase
{
    use ApprovalFixtures;
    use RefreshDatabase;

    private Approval $approval;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApprovals();

        // staff (A1) → headA → hrHead.
        $this->approval = $this->submit($this->people['staff']);
    }

    protected function tearDown(): void
    {
        $this->tearDownApprovals();
        parent::tearDown();
    }

    private function read(?Approval $approval = null): TestResponse
    {
        return $this->getJson('/api/approvals/'.($approval ?? $this->approval)->id);
    }

    // ── What it shows ────────────────────────────────────────────────────

    public function test_the_requester_reads_the_whole_live_chain(): void
    {
        $this->actingAsPerson($this->people['staff']);

        $this->read()
            ->assertOk()
            ->assertJsonPath('data.id', $this->approval->id)
            ->assertJsonPath('data.status', ApprovalStatus::Pending->value)
            ->assertJsonPath('data.status_label', 'قيد الاعتماد')
            ->assertJsonPath('data.subject.type', 'fake_approvable')
            ->assertJsonPath('data.subject.id', $this->approval->approvable_id)
            ->assertJsonPath('data.subject.title', 'طلب تجريبي')
            ->assertJsonPath('data.requester.id', $this->people['staff']->id)
            ->assertJsonPath('data.decided_at', null)
            ->assertJsonCount(2, 'data.steps')
            ->assertJsonPath('data.steps.0.step_order', 1)
            ->assertJsonPath('data.steps.0.resolver', StepResolver::DirectManager->value)
            ->assertJsonPath('data.steps.0.approver.id', $this->people['headA']->id)
            ->assertJsonPath('data.steps.0.status', StepStatus::Pending->value)
            ->assertJsonPath('data.steps.0.is_current', true)
            ->assertJsonPath('data.steps.1.resolver', StepResolver::UnitManager->value)
            ->assertJsonPath('data.steps.1.resolver_ref', 'HR')
            ->assertJsonPath('data.steps.1.approver.id', $this->people['hrHead']->id)
            ->assertJsonPath('data.steps.1.is_current', false)
            ->assertJsonPath('data.reassignments', [])
            // The requester decides nothing, and may still take it back.
            ->assertJsonPath('data.can_act', false)
            ->assertJsonPath('data.current_step_id', null)
            ->assertJsonPath('data.can_withdraw', true);
    }

    public function test_the_current_approver_is_told_which_step_is_theirs(): void
    {
        $this->actingAsPerson($this->people['headA']);

        $this->read()
            ->assertOk()
            ->assertJsonPath('data.can_act', true)
            ->assertJsonPath('data.current_step_id', $this->stepOf($this->approval, $this->people['headA'])->id)
            ->assertJsonPath('data.can_withdraw', false);
    }

    public function test_a_later_approver_reads_it_but_may_not_act_yet(): void
    {
        $this->actingAsPerson($this->people['hrHead']);

        $this->read()
            ->assertOk()
            ->assertJsonPath('data.can_act', false)
            ->assertJsonPath('data.current_step_id', null);
    }

    public function test_decisions_notes_skips_and_reassignments_are_all_shown(): void
    {
        // hrStaff's direct manager is hrHead, who is also step 2: skipped as
        // the same approver (Q2). Step 1 then goes to gm, who approves.
        $approval = $this->submit($this->people['hrStaff']);
        $first = $this->stepOf($approval, $this->people['hrHead']);
        $hr = $this->actingAsScopedUser(['approvals.reassign'], ScopeType::Company);
        $this->engine()->reassign($first, $this->people['gm'], $hr, 'المدير في مهمة');
        $gm = $this->accountOf($this->people['gm']);
        $this->engine()->act($first->fresh(), $gm, Decision::Approve, 'موافق');

        $this->actingAsPerson($this->people['hrStaff']);

        $this->read($approval)
            ->assertOk()
            ->assertJsonPath('data.status', ApprovalStatus::Approved->value)
            ->assertJsonPath('data.decided_at', fn (?string $at): bool => $at !== null)
            ->assertJsonPath('data.steps.0.approver.id', $this->people['gm']->id)
            ->assertJsonPath('data.steps.0.status', StepStatus::Approved->value)
            ->assertJsonPath('data.steps.0.acted_by.id', $gm->id)
            ->assertJsonPath('data.steps.0.acted_by.name', $this->people['gm']->full_name)
            ->assertJsonPath('data.steps.0.note', 'موافق')
            ->assertJsonPath('data.steps.1.status', StepStatus::Skipped->value)
            ->assertJsonPath('data.steps.1.skip_reason', SkipReason::SameApprover->value)
            ->assertJsonPath('data.steps.1.skip_reason_label', 'المعتمد نفسه في الخطوة السابقة')
            ->assertJsonPath('data.steps.1.is_current', false)
            ->assertJsonCount(1, 'data.reassignments')
            ->assertJsonPath('data.reassignments.0.step_id', $first->id)
            ->assertJsonPath('data.reassignments.0.step_order', 1)
            ->assertJsonPath('data.reassignments.0.from.id', $this->people['hrHead']->id)
            ->assertJsonPath('data.reassignments.0.to.id', $this->people['gm']->id)
            ->assertJsonPath('data.reassignments.0.performed_by.id', $hr->id)
            ->assertJsonPath('data.reassignments.0.reason', 'المدير في مهمة')
            ->assertJsonPath('data.can_withdraw', false);
    }

    // ── Who may read it ──────────────────────────────────────────────────

    public function test_someone_off_the_chain_is_refused(): void
    {
        $this->actingAsPerson($this->people['gm']);

        $this->read()->assertForbidden();
    }

    public function test_approvals_view_reads_a_chain_within_reach(): void
    {
        $this->actingAsScopedUser(['approvals.view'], ScopeType::Unit, $this->units['a']->id);

        $this->read()
            ->assertOk()
            ->assertJsonPath('data.can_act', false)
            ->assertJsonPath('data.can_withdraw', false);
    }

    public function test_approvals_view_beyond_reach_is_refused_saying_why(): void
    {
        $this->actingAsScopedUser(['approvals.view'], ScopeType::Unit, $this->units['hr']->id);

        $this->read()
            ->assertForbidden()
            ->assertJsonPath('message', 'طلب الاعتماد هذا لموظف خارج نطاق صلاحياتك.');
    }

    public function test_reach_follows_the_requesters_current_unit(): void
    {
        // D6: a transfer takes the person's requests with them.
        $this->people['staff']->update(['department_id' => $this->units['hr']->id]);

        $this->actingAsScopedUser(['approvals.view'], ScopeType::Unit, $this->units['a']->id);
        $this->read()->assertForbidden();

        $this->actingAsScopedUser(['approvals.view'], ScopeType::Unit, $this->units['hr']->id);
        $this->read()->assertOk();
    }

    public function test_can_reassign_says_whether_the_callers_grant_reaches_it(): void
    {
        $this->actingAsScopedUser(['approvals.view', 'approvals.reassign'], ScopeType::Unit, $this->units['a']->id);
        $this->read()->assertOk()->assertJsonPath('data.can_reassign', true);

        $this->actingAsPerson($this->people['staff']);
        $this->read()->assertOk()->assertJsonPath('data.can_reassign', false);
    }

    public function test_an_unknown_approval_is_not_found(): void
    {
        $this->actingAsPerson($this->people['staff']);

        $this->getJson('/api/approvals/999999')->assertNotFound();
    }
}
