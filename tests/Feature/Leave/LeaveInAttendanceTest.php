<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Modules\Approvals\Enums\Decision;
use App\Modules\Approvals\Enums\StepStatus;
use App\Modules\Approvals\Models\Approval;
use App\Modules\Approvals\Services\ApprovalService;
use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Enums\LeaveOrigin;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Services\LeaveApprovalRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Leave\Concerns\LeaveFixtures;
use Tests\TestCase;

/**
 * Attendance sees real leave (LEAVE_BUILD.md §4): the Leave module's
 * ApprovedLeave replaces the stand-in that told attendance nobody was ever
 * on leave. Each report's own leave rules are tested in the attendance suite
 * through a fake; this proves the real binding feeds them.
 */
class LeaveInAttendanceTest extends TestCase
{
    use LeaveFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->buildLeaveTree();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function approvedLeave(Employee $employee, string $start, string $end, int $days): LeaveRequest
    {
        $leave = LeaveRequest::factory()->forType('annual')->create([
            'employee_id' => $employee->id, 'start_date' => $start, 'end_date' => $end, 'requested_days' => $days,
        ]);
        $engine = app(ApprovalService::class);
        $engine->submit($leave, LeaveApprovalRoute::make());

        foreach ([$this->people['headA'], $this->people['hrHead']] as $approver) {
            $step = Approval::where('approvable_id', $leave->id)->sole()->steps()->where('approver_id', $approver->id)->where('status', StepStatus::Pending)->sole();
            $engine->act($step, $this->accountOf($approver), Decision::Approve);
        }

        return $leave->fresh();
    }

    private function checkIn(): TestResponse
    {
        Carbon::setTestNow('2026-06-21 08:30:00');
        $this->actingAsPerson($this->people['staff']);

        return $this->postJson('/api/me/attendance', [
            'type'        => AttendanceEventType::CheckIn->value,
            'latitude'    => 33.5,
            'longitude'   => 36.3,
            'selfie'      => UploadedFile::fake()->create('selfie.jpg', 100, 'image/jpeg'),
            'recorded_at' => '2026-06-21 08:30:00',
        ]);
    }

    private function viewer(): User
    {
        return $this->actingAsScopedUser(['attendance.records.view'], ScopeType::Company);
    }

    public function test_an_approved_leave_refuses_a_check_in_that_day(): void
    {
        $this->approvedLeave($this->people['staff'], '2026-06-20', '2026-06-22', 3);

        $this->checkIn()->assertStatus(422)->assertJsonPath('message', 'لا يمكن تسجيل الحضور أثناء إجازة معتمدة لهذا اليوم.');
    }

    public function test_a_pending_leave_does_not(): void
    {
        LeaveRequest::factory()->forType('annual')->create(['employee_id' => $this->people['staff']->id, 'start_date' => '2026-06-21', 'end_date' => '2026-06-21']);

        $this->checkIn()->assertCreated();
    }

    public function test_the_daily_board_lists_the_leave_with_who_approved_it(): void
    {
        $leave = $this->approvedLeave($this->people['staff'], '2026-06-20', '2026-06-22', 3);
        $this->viewer();

        $this->getJson('/api/attendance/report?date=2026-06-21')
            ->assertOk()
            ->assertJsonCount(1, 'data.on_leave')
            ->assertJsonPath('data.on_leave.0.leave_request_id', $leave->id)
            ->assertJsonPath('data.on_leave.0.employee.id', $this->people['staff']->id)
            ->assertJsonPath('data.on_leave.0.leave_type', 'annual')
            ->assertJsonPath('data.on_leave.0.leave_type_name', 'إجازة سنوية')
            ->assertJsonPath('data.on_leave.0.approved_by', $this->people['hrHead']->full_name);
    }

    public function test_the_monthly_report_counts_the_days_inside_each_month(): void
    {
        $this->approvedLeave($this->people['staff'], '2026-06-29', '2026-07-02', 4);
        $this->viewer();

        $row = fn (string $from, string $to): array => collect($this->getJson("/api/attendance/report/monthly?from={$from}&to={$to}")->assertOk()->json('data.rows'))
            ->firstWhere('employee.id', $this->people['staff']->id);

        $this->assertSame(2, $row('2026-06-01', '2026-06-30')['on_leave_days']);
        $this->assertSame(2, $row('2026-07-01', '2026-07-31')['on_leave_days']);
    }

    public function test_a_cancelled_leave_counts_nowhere(): void
    {
        $leave = $this->approvedLeave($this->people['staff'], '2026-06-20', '2026-06-22', 3);
        $leave->update(['status' => LeaveStatus::Cancelled]);
        $this->viewer();

        $this->getJson('/api/attendance/report?date=2026-06-21')->assertOk()->assertJsonCount(0, 'data.on_leave');
    }

    public function test_an_excuse_shows_as_excused_with_who_recorded_it(): void
    {
        $hr = $this->accountOf($this->people['hrStaff']);
        LeaveRequest::factory()->forType('sick')->excuse()->create([
            'employee_id' => $this->people['staff']->id, 'start_date' => '2026-06-21', 'end_date' => '2026-06-21',
            'origin' => LeaveOrigin::HrExcuse, 'created_by' => $hr->id, 'reason' => 'تقرير طبي',
        ]);
        $this->viewer();

        $this->getJson('/api/attendance/report?date=2026-06-21')
            ->assertOk()
            ->assertJsonCount(0, 'data.on_leave')
            ->assertJsonPath('data.excused.0.leave_type', 'sick')
            ->assertJsonPath('data.excused.0.excuse.recorded_by', $this->people['hrStaff']->full_name)
            ->assertJsonPath('data.excused.0.excuse.reason', 'تقرير طبي')
            ->assertJsonPath('data.excused.0.approved_by', null);
    }
}
