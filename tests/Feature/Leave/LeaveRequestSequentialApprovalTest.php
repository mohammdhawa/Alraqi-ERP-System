<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Modules\Approvals\Enums\SkipReason;
use App\Modules\Approvals\Enums\StepStatus;
use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Auth\Models\Notification;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveRequest;
use App\Shared\Support\NotificationEventCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Leave\Concerns\LeaveFixtures;
use Tests\TestCase;

/**
 * A filed leave through its chain, over HTTP — legacy's
 * LeaveRequestSequentialApprovalTest on the resolved chain (Q1, Q2): the
 * direct manager, then مدير الموارد; only the current step may act; a
 * rejection ends it.
 */
class LeaveRequestSequentialApprovalTest extends TestCase
{
    use LeaveFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLeaveTree();
    }

    private function fileAs(Employee $employee): array
    {
        $this->actingAsPerson($employee);

        return $this->postJson('/api/me/leave-requests', [
            'leave_type_id' => $this->type('annual')->id,
            'start_date'    => '2026-06-21',
            'end_date'      => '2026-06-22',
        ])->assertCreated()->json('data.leave_request');
    }

    private function act(array $leave, Employee $approver, string $verb = 'approve'): TestResponse
    {
        $this->actingAsPerson($approver);
        $step = collect($leave['approval']['steps'])->firstWhere('approver.id', $approver->id);

        return $this->postJson("/api/approvals/{$leave['approval']['id']}/steps/{$step['id']}/{$verb}");
    }

    private function typesFor(Employee $employee): array
    {
        return Notification::where('user_id', $this->accountOf($employee)->id)->pluck('type')->all();
    }

    public function test_submission_notifies_only_the_first_approver(): void
    {
        $this->fileAs($this->people['staff']);

        $this->assertSame([NotificationEventCatalogue::APPROVAL_STEP_PENDING], $this->typesFor($this->people['headA']));
        $this->assertSame([], $this->typesFor($this->people['hrHead']));
        $this->assertSame([], $this->typesFor($this->people['staff']), 'no receipt, as legacy');
    }

    public function test_the_steps_are_actionable_in_order_and_only_the_last_is_final(): void
    {
        $leave = $this->fileAs($this->people['staff']);

        $this->act($leave, $this->people['hrHead'])->assertStatus(422)->assertJsonPath('message', 'ليس دورك في اعتماد هذا الطلب.');

        $this->act($leave, $this->people['headA'])->assertOk();
        $this->assertSame(LeaveStatus::Pending, LeaveRequest::sole()->status);
        $this->assertSame([NotificationEventCatalogue::APPROVAL_STEP_PENDING], $this->typesFor($this->people['hrHead']));

        $this->act($leave, $this->people['hrHead'])->assertOk();
        $this->assertSame(LeaveStatus::Approved, LeaveRequest::sole()->status);
        $this->assertSame([NotificationEventCatalogue::LEAVE_APPROVED], $this->typesFor($this->people['staff']));
    }

    public function test_a_rejection_at_any_step_is_final(): void
    {
        $leave = $this->fileAs($this->people['staff']);

        $this->act($leave, $this->people['headA'], 'reject')
            ->assertOk()
            ->assertJsonPath('data.steps.1.status', StepStatus::Skipped->value)
            ->assertJsonPath('data.steps.1.skip_reason', SkipReason::ClosedByRejection->value);

        $this->assertSame(LeaveStatus::Rejected, LeaveRequest::sole()->status);
        $this->assertSame([], $this->typesFor($this->people['hrHead']), 'never asked');
    }

    public function test_an_hr_staff_members_leave_is_decided_by_the_hr_manager_alone(): void
    {
        $leave = $this->fileAs($this->people['hrStaff']);

        $this->assertSame(SkipReason::SameApprover->value, $leave['approval']['steps'][1]['skip_reason']);

        $this->act($leave, $this->people['hrHead'])->assertOk();
        $this->assertSame(LeaveStatus::Approved, LeaveRequest::sole()->status);
    }

    public function test_the_hr_managers_own_leave_is_decided_by_the_general_manager(): void
    {
        $leave = $this->fileAs($this->people['hrHead']);

        $this->assertSame($this->people['gm']->id, $leave['approval']['steps'][0]['approver']['id']);
        $this->assertSame(SkipReason::Requester->value, $leave['approval']['steps'][1]['skip_reason']);

        $this->act($leave, $this->people['gm'])->assertOk();
        $this->assertSame(LeaveStatus::Approved, LeaveRequest::sole()->status);
    }

    /**
     * The owner's answer of 2026-10-20 (parity audit C-1): nobody stands
     * above المدير العام, so their step 1 is skipped — not refused — and
     * مدير الموارد decides alone, the mirror of the HR manager's own leave.
     */
    public function test_the_general_managers_own_leave_is_decided_by_the_hr_manager(): void
    {
        $leave = $this->fileAs($this->people['gm']);

        $this->assertSame(StepStatus::Skipped->value, $leave['approval']['steps'][0]['status']);
        $this->assertSame(SkipReason::TopOfTree->value, $leave['approval']['steps'][0]['skip_reason']);
        $this->assertSame($this->people['hrHead']->id, $leave['approval']['steps'][1]['approver']['id']);
        $this->assertSame([NotificationEventCatalogue::APPROVAL_STEP_PENDING], $this->typesFor($this->people['hrHead']));

        $this->act($leave, $this->people['hrHead'])->assertOk();
        $this->assertSame(LeaveStatus::Approved, LeaveRequest::sole()->status);
    }

    public function test_a_reassigned_step_is_decided_by_its_new_approver(): void
    {
        $leave = $this->fileAs($this->people['staff']);
        $first = $leave['approval']['steps'][0]['id'];

        $this->actingAsScopedUser(['approvals.reassign'], ScopeType::Company);
        $this->patchJson("/api/approvals/{$leave['approval']['id']}/steps/{$first}/assignee", [
            'employee_id' => $this->people['bStaff']->id, 'reason' => 'المدير في إجازة',
        ])->assertOk();

        $this->actingAsPerson($this->people['headA']);
        $this->postJson("/api/approvals/{$leave['approval']['id']}/steps/{$first}/approve")->assertForbidden();

        $this->actingAsPerson($this->people['bStaff']);
        $this->postJson("/api/approvals/{$leave['approval']['id']}/steps/{$first}/approve")->assertOk();
    }
}
