<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Modules\Approvals\Enums\Decision;
use App\Modules\Approvals\Enums\SkipReason;
use App\Modules\Approvals\Enums\StepResolver;
use App\Modules\Approvals\Enums\StepStatus;
use App\Modules\Approvals\Exceptions\ApprovalException;
use App\Modules\Approvals\Models\Approval;
use App\Modules\Approvals\Services\ApprovalService;
use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Auth\Models\Notification;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Services\LeaveApprovalRoute;
use App\Shared\Support\NotificationEventCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Leave\Concerns\LeaveFixtures;
use Tests\TestCase;

/**
 * A leave request as the approvals engine's subject (LEAVE_BUILD.md §4) —
 * the chain it resolves, the final-approval guard, the status the hook
 * writes and legacy's words in every notification. Driven through the engine
 * directly; filing over HTTP is LeaveRequestSubmissionTest's.
 */
class LeaveApprovalSubjectTest extends TestCase
{
    use LeaveFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLeaveTree();
    }

    private function engine(): ApprovalService
    {
        return app(ApprovalService::class);
    }

    private function file(Employee $employee, string $type = 'annual', int $days = 2, string $start = '2026-06-21', string $end = '2026-06-22'): LeaveRequest
    {
        $leave = LeaveRequest::factory()->forType($type)->create([
            'employee_id' => $employee->id, 'start_date' => $start, 'end_date' => $end, 'requested_days' => $days,
        ]);
        $this->engine()->submit($leave, LeaveApprovalRoute::make());

        return $leave->fresh();
    }

    private function approval(LeaveRequest $leave): Approval
    {
        return Approval::where('approvable_type', 'leave_request')->where('approvable_id', $leave->id)->sole();
    }

    private function decide(LeaveRequest $leave, Employee $approver, Decision $decision = Decision::Approve): void
    {
        $step = $this->approval($leave)->steps()->where('approver_id', $approver->id)->where('status', StepStatus::Pending)->firstOrFail();
        $this->engine()->act($step, $this->accountOf($approver), $decision);
    }

    /** @return array{0: ?string, 1: ?string} title and body of the person's last notification of $type */
    private function told(Employee $employee, string $type): array
    {
        $notification = Notification::where('user_id', $this->accountOf($employee)->id)->where('type', $type)->latest('id')->first();

        return [$notification?->title, $notification?->body];
    }

    // ── The chain ────────────────────────────────────────────────────────

    public function test_the_chain_is_the_direct_manager_then_the_hr_manager(): void
    {
        $steps = $this->approval($this->file($this->people['staff']))->steps;

        $this->assertSame([$this->people['headA']->id, $this->people['hrHead']->id], $steps->pluck('approver_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(StepResolver::DirectManager, $steps[0]->resolver);
        $this->assertSame(StepResolver::UnitManager, $steps[1]->resolver);
        $this->assertSame('HR', $steps[1]->resolver_ref);
    }

    public function test_an_hr_staff_members_leave_needs_one_approval(): void
    {
        $steps = $this->approval($this->file($this->people['hrStaff']))->steps;

        $this->assertSame(StepStatus::Pending, $steps[0]->status);
        $this->assertSame(StepStatus::Skipped, $steps[1]->status);
        $this->assertSame(SkipReason::SameApprover, $steps[1]->skip_reason);
    }

    // ── The outcome, written by the hook ─────────────────────────────────

    public function test_approved_at_both_steps_the_leave_is_approved_and_the_employee_told(): void
    {
        $leave = $this->file($this->people['staff']);

        $this->decide($leave, $this->people['headA']);
        $this->assertSame(LeaveStatus::Pending, $leave->fresh()->status, 'one approval of two decides nothing');

        $this->decide($leave, $this->people['hrHead']);

        $leave->refresh();
        $this->assertSame(LeaveStatus::Approved, $leave->status);
        $this->assertNotNull($leave->decided_at);
        $this->assertSame(
            ['تمت الموافقة على طلب الإجازة', 'تمت الموافقة على طلب إجازتك من 2026-06-21 إلى 2026-06-22.'],
            $this->told($this->people['staff'], NotificationEventCatalogue::LEAVE_APPROVED),
        );
    }

    public function test_a_rejection_rejects_the_leave_and_the_employee_is_told(): void
    {
        $leave = $this->file($this->people['staff']);

        $this->decide($leave, $this->people['headA'], Decision::Reject);

        $this->assertSame(LeaveStatus::Rejected, $leave->fresh()->status);
        $this->assertSame(
            ['تم رفض طلب الإجازة', 'تم رفض طلب إجازتك من 2026-06-21 إلى 2026-06-22.'],
            $this->told($this->people['staff'], NotificationEventCatalogue::LEAVE_REJECTED),
        );
    }

    public function test_a_withdrawal_cancels_the_leave_and_tells_nobody(): void
    {
        $leave = $this->file($this->people['staff']);

        $this->engine()->withdraw($this->approval($leave), $this->accountOf($this->people['staff']));

        $this->assertSame(LeaveStatus::Cancelled, $leave->fresh()->status);
        $this->assertSame(0, Notification::where('user_id', $this->accountOf($this->people['staff'])->id)->count());
    }

    // ── The final-approval guard ─────────────────────────────────────────

    public function test_two_pending_requests_cannot_together_overdraw_the_balance(): void
    {
        LeaveBalance::create(['employee_id' => $this->people['staff']->id, 'year' => 2026, 'allocated_days' => 5, 'adjustment_days' => 0]);
        $first = $this->file($this->people['staff'], days: 3, start: '2026-06-01', end: '2026-06-03');
        $second = $this->file($this->people['staff'], days: 3, start: '2026-06-10', end: '2026-06-12');

        foreach ([$first, $second] as $leave) {
            $this->decide($leave, $this->people['headA']);
        }
        $this->decide($first, $this->people['hrHead']);

        try {
            $this->decide($second, $this->people['hrHead']);
            $this->fail('the second final approval should be refused');
        } catch (ApprovalException $refusal) {
            $this->assertSame('طلب الإجازة يتجاوز رصيد الإجازات المتبقي للموظف.', $refusal->getMessage());
        }

        $this->assertSame(LeaveStatus::Approved, $first->fresh()->status);
        $this->assertSame(LeaveStatus::Pending, $second->fresh()->status);
    }

    public function test_a_non_deducting_leave_is_approved_with_a_spent_balance(): void
    {
        LeaveBalance::create(['employee_id' => $this->people['staff']->id, 'year' => 2026, 'allocated_days' => 0, 'adjustment_days' => 0]);
        $leave = $this->file($this->people['staff'], 'sick', days: 4, start: '2026-06-01', end: '2026-06-04');

        $this->decide($leave, $this->people['headA']);
        $this->decide($leave, $this->people['hrHead']);

        $this->assertSame(LeaveStatus::Approved, $leave->fresh()->status);
    }

    // ── What the approvers read ──────────────────────────────────────────

    public function test_each_approver_is_told_in_legacys_words(): void
    {
        $name = $this->people['staff']->full_name;
        $leave = $this->file($this->people['staff']);

        $this->assertSame(['طلب إجازة جديد', "{$name} قدّم طلب إجازة."], $this->told($this->people['headA'], NotificationEventCatalogue::APPROVAL_STEP_PENDING));

        $this->decide($leave, $this->people['headA']);
        $this->assertSame(['دورك لمراجعة طلب إجازة', "يرجى مراجعة طلب إجازة {$name}."], $this->told($this->people['hrHead'], NotificationEventCatalogue::APPROVAL_STEP_PENDING));
    }

    public function test_a_reassigned_approver_is_told_in_legacys_words(): void
    {
        $name = $this->people['staff']->full_name;
        $approval = $this->approval($this->file($this->people['staff']));
        $hr = $this->actingAsScopedUser(['approvals.reassign'], ScopeType::Company);

        $this->engine()->reassign($approval->steps[0], $this->people['bStaff'], $hr, 'المدير في إجازة');
        $this->assertSame(['تم إسنادك لاعتماد طلب إجازة', "طلب إجازة {$name} بانتظار مراجعتك الآن."], $this->told($this->people['bStaff'], NotificationEventCatalogue::APPROVAL_STEP_PENDING));

        $this->engine()->reassign($approval->steps[1], $this->people['gm'], $hr, 'مدير الموارد في مهمة');
        $this->assertSame(['تم إسنادك لاعتماد طلب إجازة', "أصبحت ضمن مسار اعتماد طلب إجازة {$name}."], $this->told($this->people['gm'], NotificationEventCatalogue::APPROVAL_STEP_ASSIGNED));
    }

    public function test_the_inbox_row_summarises_the_leave(): void
    {
        $this->file($this->people['staff']);
        $this->actingAsPerson($this->people['headA']);

        $this->getJson('/api/me/approvals')
            ->assertOk()
            ->assertJsonPath('data.0.subject.type', 'leave_request')
            ->assertJsonPath('data.0.subject.title', 'إجازة سنوية')
            ->assertJsonPath('data.0.subject.summary', '2 أيام · 2026-06-21 → 2026-06-22');
    }
}
