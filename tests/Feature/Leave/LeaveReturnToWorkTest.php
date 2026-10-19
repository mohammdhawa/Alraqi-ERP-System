<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Modules\Approvals\Enums\ApprovalStatus;
use App\Modules\Approvals\Enums\Decision;
use App\Modules\Approvals\Enums\StepStatus;
use App\Modules\Approvals\Models\Approval;
use App\Modules\Approvals\Services\ApprovalService;
use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Auth\Models\Notification;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Services\LeaveApprovalRoute;
use App\Shared\Support\NotificationEventCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Leave\Concerns\LeaveFixtures;
use Tests\TestCase;

/**
 * The employee came back before their approved leave was over — legacy's
 * LeaveReturnToWorkTest (LEAVE_BUILD.md §6). The days taken stand; the leave
 * is cut back to the day before the return, so the rest are free for a
 * check-in and come back to the balance. Back on the first day: the leave is
 * cancelled. The approval is untouched — the decision stands for the days it
 * covered.
 */
class LeaveReturnToWorkTest extends TestCase
{
    use LeaveFixtures;
    use RefreshDatabase;

    private const RETURN = ['leave.requests.returnToWork'];

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->buildLeaveTree();
        $this->hr = $this->actingAsScopedUser(self::RETURN, ScopeType::Company);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** An approved five-day annual leave, 21–25 June, through its chain. */
    private function approvedLeave(string $type = 'annual', ?Employee $employee = null, string $start = '2026-06-21', string $end = '2026-06-25', int $days = 5): LeaveRequest
    {
        $employee ??= $this->people['staff'];
        $leave = LeaveRequest::factory()->forType($type)->create([
            'employee_id' => $employee->id, 'start_date' => $start, 'end_date' => $end, 'requested_days' => $days,
        ]);
        $engine = app(ApprovalService::class);
        $engine->submit($leave, LeaveApprovalRoute::make());

        foreach ([$this->people['headA'], $this->people['hrHead']] as $approver) {
            $step = Approval::where('approvable_id', $leave->id)->sole()->steps()->where('approver_id', $approver->id)->where('status', StepStatus::Pending)->first();
            if ($step !== null) {
                $engine->act($step, $this->accountOf($approver), Decision::Approve);
            }
        }

        return $leave->fresh();
    }

    private function back(LeaveRequest $leave, array $body = []): TestResponse
    {
        return $this->postJson("/api/leave/requests/{$leave->id}/return-to-work", $body);
    }

    // ── Cutting it short ─────────────────────────────────────────────────

    public function test_the_leave_is_cut_back_to_the_day_before_the_return(): void
    {
        $leave = $this->approvedLeave();

        $this->back($leave, ['returned_on' => '2026-06-23', 'reason' => 'انتهى الظرف'])
            ->assertOk()
            ->assertJsonPath('message', 'تم تقصير الإجازة إلى الأيام المأخوذة فعلاً.')
            ->assertJsonPath('data.cancelled', false)
            ->assertJsonPath('data.days_returned', 3)
            ->assertJsonPath('data.leave_request.end_date', '2026-06-22')
            ->assertJsonPath('data.leave_request.requested_days', 2)
            ->assertJsonPath('data.leave_request.original_end_date', '2026-06-25')
            ->assertJsonPath('data.leave_request.returned_to_work_on', '2026-06-23')
            ->assertJsonPath('data.leave_request.status', LeaveStatus::Approved->value)
            ->assertJsonPath('data.leave_request.shortened_by.id', $this->hr->id)
            ->assertJsonPath('data.leave_request.shortening_reason', 'انتهى الظرف')
            ->assertJsonPath('data.balance.used_days', 2)
            ->assertJsonPath('data.balance.remaining_days', 13);
    }

    public function test_the_employee_can_check_in_on_the_day_they_came_back(): void
    {
        $leave = $this->approvedLeave();
        $this->back($leave, ['returned_on' => '2026-06-23'])->assertOk();

        Carbon::setTestNow('2026-06-23 08:30:00');
        $this->actingAsPerson($this->people['staff']);
        $this->postJson('/api/me/attendance', [
            'type'        => AttendanceEventType::CheckIn->value,
            'latitude'    => 33.5,
            'longitude'   => 36.3,
            'selfie'      => UploadedFile::fake()->create('selfie.jpg', 100, 'image/jpeg'),
            'recorded_at' => '2026-06-23 08:30:00',
        ])->assertCreated();
    }

    public function test_a_non_deducting_leave_returns_no_balance_days(): void
    {
        $leave = $this->approvedLeave('unpaid');

        $this->back($leave, ['returned_on' => '2026-06-23'])->assertOk()->assertJsonPath('data.days_returned', 0);
    }

    public function test_the_approval_is_left_alone(): void
    {
        $leave = $this->approvedLeave();
        $this->back($leave, ['returned_on' => '2026-06-23'])->assertOk();

        $approval = Approval::where('approvable_id', $leave->id)->sole();
        $this->assertSame(ApprovalStatus::Approved, $approval->status);
        $this->assertSame([StepStatus::Approved, StepStatus::Approved], $approval->steps->pluck('status')->all());
    }

    public function test_shortening_twice_keeps_the_span_that_was_granted(): void
    {
        $leave = $this->approvedLeave();
        $this->back($leave, ['returned_on' => '2026-06-24'])->assertOk();

        $this->back($leave, ['returned_on' => '2026-06-23'])
            ->assertOk()
            ->assertJsonPath('data.leave_request.original_end_date', '2026-06-25')
            ->assertJsonPath('data.leave_request.end_date', '2026-06-22');
    }

    public function test_the_employee_is_told_their_leave_changed(): void
    {
        $this->back($this->approvedLeave(), ['returned_on' => '2026-06-23', 'reason' => 'انتهى الظرف'])->assertOk();

        $told = Notification::where('user_id', $this->accountOf($this->people['staff'])->id)
            ->where('type', NotificationEventCatalogue::LEAVE_RETURNED_TO_WORK)->sole();

        $this->assertSame('تم تعديل إجازتك لعودتك إلى العمل', $told->title);
        $this->assertSame('تم إنهاء (إجازة سنوية) المعتمدة بتاريخ 2026-06-22 بدلاً من 2026-06-25 لعودتك إلى العمل بتاريخ 2026-06-23. وأُعيد إلى رصيدك 3 يوم. السبب: انتهى الظرف', $told->body);
    }

    // ── Back on the first day ────────────────────────────────────────────

    public function test_returning_on_the_first_day_cancels_the_whole_leave(): void
    {
        $leave = $this->approvedLeave();

        $this->back($leave, ['returned_on' => '2026-06-21'])
            ->assertOk()
            ->assertJsonPath('message', 'أُلغيت الإجازة لعودة الموظف في يومها الأول.')
            ->assertJsonPath('data.cancelled', true)
            ->assertJsonPath('data.days_returned', 5)
            ->assertJsonPath('data.leave_request.status', LeaveStatus::Cancelled->value)
            ->assertJsonPath('data.leave_request.requested_days', 5)
            ->assertJsonPath('data.leave_request.cancelled_by.id', $this->hr->id)
            ->assertJsonPath('data.balance.used_days', 0);

        $this->assertSame(
            'تم إلغاء (إجازة سنوية) المعتمدة من 2026-06-21 إلى 2026-06-25 لعودتك إلى العمل بتاريخ 2026-06-21. وأُعيد إلى رصيدك 5 يوم.',
            Notification::where('type', NotificationEventCatalogue::LEAVE_RETURNED_TO_WORK)->sole()->body,
        );
    }

    public function test_a_single_day_leave_is_cancelled_outright(): void
    {
        $leave = $this->approvedLeave(start: '2026-06-21', end: '2026-06-21', days: 1);

        $this->back($leave, ['returned_on' => '2026-06-21'])->assertOk()->assertJsonPath('data.cancelled', true);
    }

    public function test_the_return_date_defaults_to_today(): void
    {
        Carbon::setTestNow('2026-06-24 10:00:00');

        $this->back($this->approvedLeave())->assertOk()->assertJsonPath('data.leave_request.returned_to_work_on', '2026-06-24');
    }

    // ── Refusals ─────────────────────────────────────────────────────────

    public function test_a_return_date_outside_the_leave_is_refused(): void
    {
        $leave = $this->approvedLeave();

        $this->back($leave, ['returned_on' => '2026-06-26'])->assertStatus(422)->assertJsonPath('message', 'يجب أن يقع تاريخ العودة ضمن فترة الإجازة.');
        $this->back($leave, ['returned_on' => '2026-06-20'])->assertStatus(422);
        $this->back($leave, ['returned_on' => 'غداً'])->assertStatus(422)->assertJsonValidationErrors('returned_on');
    }

    public function test_an_excuse_is_refused_and_pointed_at_its_undo(): void
    {
        $excuse = LeaveRequest::factory()->forType('annual')->excuse()->create(['employee_id' => $this->people['staff']->id, 'start_date' => '2026-06-21', 'end_date' => '2026-06-25']);

        $this->back($excuse, ['returned_on' => '2026-06-23'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'هذا عذر غياب سجّلته الموارد البشرية، وليس إجازة جارية. تراجع عنه بدلاً من ذلك.');
    }

    public function test_only_an_approved_leave_can_be_cut_short(): void
    {
        $pending = LeaveRequest::factory()->forType('annual')->create(['employee_id' => $this->people['staff']->id, 'start_date' => '2026-06-21', 'end_date' => '2026-06-25']);
        $this->back($pending, ['returned_on' => '2026-06-23'])->assertStatus(422)->assertJsonPath('message', 'يمكن إنهاء الإجازة المعتمدة فقط بالعودة إلى العمل.');

        $cancelled = $this->approvedLeave(start: '2026-07-01', end: '2026-07-05');
        $this->back($cancelled, ['returned_on' => '2026-07-01'])->assertOk();
        $this->back($cancelled, ['returned_on' => '2026-07-01'])->assertStatus(422);
    }

    // ── Who may record it ────────────────────────────────────────────────

    public function test_a_grant_that_does_not_reach_the_employee_is_refused(): void
    {
        $leave = $this->approvedLeave();
        $this->actingAsScopedUser(self::RETURN, ScopeType::Unit, $this->units['b']->id);

        $this->back($leave, ['returned_on' => '2026-06-23'])->assertForbidden()->assertJsonPath('message', 'هذا الموظف خارج نطاق صلاحياتك.');
    }

    public function test_the_employee_cannot_cut_their_own_leave_short(): void
    {
        $leave = $this->approvedLeave();
        $this->actingAsPerson($this->people['staff']);

        $this->back($leave, ['returned_on' => '2026-06-23'])->assertForbidden()->assertJsonPath('message', 'ليس لديك الصلاحيات الكافية لتنفيذ هذا الإجراء.');
    }
}
