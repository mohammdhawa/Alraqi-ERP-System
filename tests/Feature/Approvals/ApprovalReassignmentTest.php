<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Modules\Approvals\Enums\ApprovalStatus;
use App\Modules\Approvals\Enums\Decision;
use App\Modules\Approvals\Enums\StepStatus;
use App\Modules\Approvals\Exceptions\ApprovalException;
use App\Modules\Approvals\Models\Approval;
use App\Modules\Approvals\Models\ApprovalReassignment;
use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Auth\Models\Notification;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use App\Shared\Support\NotificationEventCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Approvals\Concerns\ApprovalFixtures;
use Tests\TestCase;

/**
 * Reassigning a pending step (design §6) — legacy's
 * LeaveApprovalReassignmentTest, on the engine.
 *
 * It moves the assignment and never decides; every hop is logged with a
 * reason. Who may call it is the route's `approvals.reassign` (§9 Q4, step
 * 2); who it may go to is here: any active employee with an active account,
 * not the requester, not already on the chain (§9 Q7 = A — legacy required a
 * manager or chief role).
 */
class ApprovalReassignmentTest extends TestCase
{
    use ApprovalFixtures;
    use RefreshDatabase;

    private Approval $approval;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApprovals();

        $this->approval = $this->submit($this->people['staff']);
        $this->hr = $this->accountOf($this->people['hrStaff']);
    }

    protected function tearDown(): void
    {
        $this->tearDownApprovals();
        parent::tearDown();
    }

    private function step(int $order): ApprovalStep
    {
        return $this->approval->steps()->where('step_order', $order)->sole();
    }

    private function reassign(int $order, Employee $to, string $reason = 'المعتمد غادر الشركة.'): ApprovalStep
    {
        return $this->engine()->reassign($this->step($order), $to, $this->hr, $reason);
    }

    private function assertRefused(callable $reassign, string $message): void
    {
        try {
            $reassign();
            $this->fail('The reassignment should have been refused.');
        } catch (ApprovalException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertSame(422, $e->statusCode());
        }

        $this->assertSame(0, ApprovalReassignment::count());
    }

    public function test_a_stuck_current_step_is_handed_to_someone_who_can_act(): void
    {
        $replacement = $this->person();

        $step = $this->reassign(1, $replacement, '  المعتمد الأول غادر الشركة.  ');

        $this->assertSame($replacement->id, (int) $step->approver_id);
        $this->assertSame(StepStatus::Pending, $step->status);

        $log = ApprovalReassignment::sole();
        $this->assertSame($this->approval->id, (int) $log->approval_id);
        $this->assertSame($step->id, (int) $log->approval_step_id);
        $this->assertSame(1, $log->step_order);
        $this->assertSame($this->people['headA']->id, (int) $log->from_employee_id);
        $this->assertSame($replacement->id, (int) $log->to_employee_id);
        $this->assertSame($this->hr->id, (int) $log->performed_by);
        $this->assertSame('المعتمد الأول غادر الشركة.', $log->reason);

        $notice = Notification::where('user_id', $this->accountOf($replacement)->id)->sole();
        $this->assertSame(NotificationEventCatalogue::APPROVAL_STEP_PENDING, $notice->type);
        $this->assertSame('عنوان ReassignedNow', $notice->title);

        // The old approver no longer holds it; the new one decides, in order.
        try {
            $this->engine()->act($step, $this->accountOf($this->people['headA']), Decision::Approve);
            $this->fail('The previous approver must not act on a reassigned step.');
        } catch (ApprovalException $e) {
            $this->assertSame('ليس دورك في اعتماد هذا الطلب.', $e->getMessage());
        }

        $this->decide($this->approval, $replacement);
        $approval = $this->decide($this->approval, $this->people['hrHead']);
        $this->assertSame(ApprovalStatus::Approved, $approval->status);
    }

    public function test_reassigning_a_later_step_leaves_the_current_turn_alone(): void
    {
        $replacement = $this->person();

        $this->reassign(2, $replacement, 'مدير الموارد في إجازة طويلة.');

        $this->assertSame(1, $this->approval->fresh('steps')->currentOrder());
        $this->assertSame($this->people['headA']->id, (int) $this->step(1)->approver_id);

        $notice = Notification::where('user_id', $this->accountOf($replacement)->id)->sole();
        $this->assertSame(NotificationEventCatalogue::APPROVAL_STEP_ASSIGNED, $notice->type);
        $this->assertSame('عنوان ReassignedAhead', $notice->title);

        try {
            $this->decide($this->approval, $replacement);
            $this->fail('A later step is still not their turn.');
        } catch (ApprovalException $e) {
            $this->assertSame('ليس دورك في اعتماد هذا الطلب.', $e->getMessage());
        }
    }

    public function test_a_decided_step_and_a_decided_request_cannot_be_reassigned(): void
    {
        $replacement = $this->person();
        $this->decide($this->approval, $this->people['headA']);

        // Step 1 has been decided; its holder is now a matter of record.
        $this->assertRefused(fn () => $this->reassign(1, $replacement), 'يمكن إعادة إسناد خطوة بانتظار القرار فقط.');

        $this->decide($this->approval, $this->people['hrHead'], Decision::Reject);

        $this->assertRefused(fn () => $this->reassign(2, $replacement), 'يمكن إعادة إسناد الطلبات قيد الاعتماد فقط.');
    }

    public function test_it_cannot_reach_an_arrangement_submission_refuses(): void
    {
        // The requester deciding their own request.
        $this->assertRefused(fn () => $this->reassign(1, $this->people['staff']), 'لا يمكن إسناد الاعتماد إلى مُقدِّم الطلب نفسه.');

        // The same person twice on one chain — one approver, one decision.
        $this->assertRefused(fn () => $this->reassign(1, $this->people['hrHead']), 'هذا الموظف يحمل خطوة أخرى في مسار اعتماد هذا الطلب.');

        // To the person who already holds it.
        $this->assertRefused(fn () => $this->reassign(1, $this->people['headA']), 'هذه الخطوة مسندة إلى هذا الموظف أصلاً.');
    }

    public function test_only_an_active_employee_with_an_active_account_may_receive_it(): void
    {
        $message = 'لا يمكن الإسناد إلا إلى موظف نشط لديه حساب فعّال.';

        $suspended = $this->person();
        $suspended->update(['status' => EmployeeStatus::Suspended]);
        $this->assertRefused(fn () => $this->reassign(1, $suspended), $message);

        $noAccount = $this->person(withAccount: false);
        $this->assertRefused(fn () => $this->reassign(1, $noAccount), $message);

        $disabled = $this->person();
        $this->accountOf($disabled)->update(['is_active' => false]);
        $this->assertRefused(fn () => $this->reassign(1, $disabled), $message);

        $departed = $this->person();
        $departed->delete();
        $this->assertRefused(fn () => $this->reassign(1, $departed), $message);

        // A deputy who manages no unit is fine (Q7 = A).
        $this->reassign(1, $this->person());
        $this->assertSame(1, ApprovalReassignment::count());
    }

    public function test_a_reason_of_three_to_five_hundred_characters_is_required(): void
    {
        $message = 'سبب إعادة الإسناد مطلوب، من 3 إلى 500 حرف.';

        $this->assertRefused(fn () => $this->reassign(1, $this->person(), '  أب  '), $message);
        $this->assertRefused(fn () => $this->reassign(1, $this->person(), str_repeat('س', 501)), $message);

        $this->reassign(1, $this->person(), str_repeat('س', 500));
        $this->assertSame(1, ApprovalReassignment::count());
    }

    public function test_the_trail_keeps_every_hop(): void
    {
        [$viaOne, $viaTwo] = [$this->person(), $this->person()];

        $this->reassign(1, $viaOne, 'المعتمد الأول غادر.');
        $this->reassign(1, $viaTwo, 'البديل الأول انتقل لقسم آخر.');

        $hops = ApprovalReassignment::orderBy('id')->get();
        $this->assertCount(2, $hops);
        $this->assertSame([$this->people['headA']->id, $viaOne->id], $hops->pluck('from_employee_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([$viaOne->id, $viaTwo->id], $hops->pluck('to_employee_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(StepStatus::Pending, $this->step(1)->status);
    }
}
