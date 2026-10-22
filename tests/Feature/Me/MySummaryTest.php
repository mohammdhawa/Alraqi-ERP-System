<?php

declare(strict_types=1);

namespace Tests\Feature\Me;

use App\Modules\Attendance\Enums\AttendanceDayState;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Auth\Models\Notification;
use App\Modules\HR\Enums\EmployeeDocumentType;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\HR\Models\JobTitle;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Leave\Concerns\LeaveFixtures;
use Tests\TestCase;

/**
 * GET /api/me/summary — the home screen (requirements §1 area 1, §6.2; work
 * order §4.8). One object of cards, each `null` until its module can answer;
 * today only `salary` is, until Stage 4 payroll.
 *
 * TODAY, BEFORE ANYTHING IS RECORDED, IS NOT "ABSENT" (the owner's answer of
 * 2026-10-19): the day is not over and the check-in window is open all day
 * (Q9), so today's card says `status: null`, and the month's figures leave
 * that day out of both the expected and the absent days.
 *
 * Over LeaveFixtures' tree: staff works in the vacant A1, so their manager is
 * headA; leave's chain is staff → headA → hrHead.
 */
class MySummaryTest extends TestCase
{
    use LeaveFixtures;
    use RefreshDatabase;

    /** Sunday 21 June 2026 — every day is a working day (Q8). */
    private const TODAY = '2026-06-21';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::TODAY.' 12:00:00');
        $this->buildLeaveTree();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function summary(?Employee $as = null): TestResponse
    {
        $this->actingAsPerson($as ?? $this->people['staff']);

        return $this->getJson('/api/me/summary')->assertOk();
    }

    private function checkIn(string $at, ?Employee $employee = null): void
    {
        AttendanceRecord::factory()->checkIn()->create(['employee_id' => ($employee ?? $this->people['staff'])->id, 'recorded_at' => $at]);
    }

    private function checkOut(string $at, float $hours, ?Employee $employee = null): void
    {
        AttendanceRecord::factory()->checkOut()->create(['employee_id' => ($employee ?? $this->people['staff'])->id, 'recorded_at' => $at, 'work_hours' => $hours]);
    }

    public function test_it_is_one_object_of_cards_and_salary_is_null_until_payroll(): void
    {
        $data = $this->summary()->json('data');

        $this->assertSame(
            ['employee', 'attendance_today', 'attendance_month', 'leave_balance', 'notifications', 'approvals', 'leave_requests', 'salary'],
            array_keys($data),
        );
        $this->assertNull($data['salary']);
    }

    public function test_the_employee_card_names_the_person_their_unit_title_and_manager(): void
    {
        $staff = $this->people['staff'];
        $staff->update(['job_title_id' => JobTitle::create(['name' => 'محاسب'])->id, 'workplace' => 'المعمل']);

        $this->summary()
            ->assertJsonPath('data.employee.id', $staff->id)
            ->assertJsonPath('data.employee.full_name', $staff->full_name)
            ->assertJsonPath('data.employee.employee_number', $staff->employee_number)
            ->assertJsonPath('data.employee.job_title.name', 'محاسب')
            ->assertJsonPath('data.employee.department.id', $this->units['a1']->id)
            ->assertJsonPath('data.employee.department.name', 'قسم أ-1')
            ->assertJsonPath('data.employee.workplace', 'المعمل')
            ->assertJsonPath('data.employee.manager.id', $this->people['headA']->id, 'past the vacant A1')
            ->assertJsonPath('data.employee.has_photo', false)
            ->assertJsonMissingPath('data.employee.agreed_salary');

        EmployeeDocument::create(['employee_id' => $staff->id, 'type' => EmployeeDocumentType::PersonalPhoto, 'file_path' => 'p.jpg']);
        $this->summary()->assertJsonPath('data.employee.has_photo', true);
    }

    public function test_today_with_nothing_recorded_is_not_absent_and_the_month_leaves_it_out(): void
    {
        $this->checkIn('2026-06-10 08:00:00');
        $this->checkOut('2026-06-10 16:00:00', 8.0);
        $this->checkIn('2026-06-20 08:00:00');
        $this->checkOut('2026-06-20 15:30:00', 7.5);
        // A colleague's day counts nowhere here.
        $this->checkIn(self::TODAY.' 08:00:00', $this->people['bStaff']);

        $this->summary()
            ->assertJsonPath('data.attendance_today.date', self::TODAY)
            ->assertJsonPath('data.attendance_today.status', null)
            ->assertJsonPath('data.attendance_today.status_label', 'لم يُسجَّل بعد')
            ->assertJsonPath('data.attendance_today.check_in_time', null)
            ->assertJsonPath('data.attendance_month.from', '2026-06-01')
            ->assertJsonPath('data.attendance_month.to', self::TODAY)
            ->assertJsonPath('data.attendance_month.expected_days', 20)
            ->assertJsonPath('data.attendance_month.present_days', 2)
            ->assertJsonPath('data.attendance_month.absent_days', 18)
            ->assertJsonPath('data.attendance_month.on_leave_days', 0)
            ->assertJsonPath('data.attendance_month.total_work_hours', 15.5);
    }

    public function test_today_checked_in_then_out(): void
    {
        $this->checkIn(self::TODAY.' 08:00:00');

        $this->summary()
            ->assertJsonPath('data.attendance_today.status', AttendanceDayState::CheckedIn->value)
            ->assertJsonPath('data.attendance_today.status_label', AttendanceDayState::CheckedIn->label())
            ->assertJsonPath('data.attendance_today.check_in_time', Carbon::parse(self::TODAY.' 08:00:00')->toIso8601String())
            ->assertJsonPath('data.attendance_month.expected_days', 21)
            ->assertJsonPath('data.attendance_month.present_days', 1)
            ->assertJsonPath('data.attendance_month.absent_days', 20);

        $this->checkOut(self::TODAY.' 11:30:00', 3.5);

        $this->summary()
            ->assertJsonPath('data.attendance_today.status', AttendanceDayState::CheckedOut->value)
            ->assertJsonPath('data.attendance_today.work_hours', 3.5)
            ->assertJsonPath('data.attendance_month.total_work_hours', 3.5);
    }

    public function test_today_on_approved_leave(): void
    {
        LeaveRequest::factory()->forType('annual')->approved()->create([
            'employee_id' => $this->people['staff']->id, 'start_date' => self::TODAY, 'end_date' => '2026-06-22', 'requested_days' => 2,
        ]);

        $this->summary()
            ->assertJsonPath('data.attendance_today.status', AttendanceDayState::OnLeave->value)
            ->assertJsonPath('data.attendance_month.expected_days', 21)
            ->assertJsonPath('data.attendance_month.on_leave_days', 1)
            ->assertJsonPath('data.attendance_month.absent_days', 20);
    }

    public function test_the_leave_balance_card_is_this_years_balance(): void
    {
        LeaveRequest::factory()->forType('annual')->approved()->create([
            'employee_id' => $this->people['staff']->id, 'start_date' => '2026-03-01', 'end_date' => '2026-03-03', 'requested_days' => 3,
        ]);

        $this->actingAsPerson($this->people['staff']);
        $own = $this->getJson('/api/me/leave-balance')->assertOk()->json('data');

        $this->summary()
            ->assertJsonPath('data.leave_balance', $own)
            ->assertJsonPath('data.leave_balance.year', 2026)
            ->assertJsonPath('data.leave_balance.used_days', 3);
    }

    public function test_the_unread_notifications_are_the_callers_own(): void
    {
        $mine = $this->accountOf($this->people['staff']);
        $theirs = $this->accountOf($this->people['bStaff']);
        Notification::create(['user_id' => $mine->id, 'title' => 'أ', 'is_read' => false]);
        Notification::create(['user_id' => $mine->id, 'title' => 'ب', 'is_read' => false]);
        Notification::create(['user_id' => $mine->id, 'title' => 'ج', 'is_read' => true]);
        Notification::create(['user_id' => $theirs->id, 'title' => 'د', 'is_read' => false]);

        $this->summary()->assertJsonPath('data.notifications.unread_count', 2);
    }

    public function test_the_callers_own_requests_still_in_the_chain(): void
    {
        $staff = $this->people['staff'];
        LeaveRequest::factory()->create(['employee_id' => $staff->id, 'status' => LeaveStatus::Pending]);
        LeaveRequest::factory()->approved()->create(['employee_id' => $staff->id, 'start_date' => '2026-05-01', 'end_date' => '2026-05-01', 'requested_days' => 1]);
        LeaveRequest::factory()->create(['employee_id' => $this->people['bStaff']->id, 'status' => LeaveStatus::Pending]);

        $this->summary()->assertJsonPath('data.leave_requests.pending_count', 1);
    }

    public function test_the_approvals_waiting_on_the_caller(): void
    {
        $this->actingAsPerson($this->people['staff']);
        $this->postJson('/api/me/leave-requests', [
            'leave_type_id' => $this->type('annual')->id, 'start_date' => '2026-06-23', 'end_date' => '2026-06-23',
        ])->assertCreated();

        $this->summary($this->people['headA'])->assertJsonPath('data.approvals.pending_count', 1);
        // Not hrHead's yet: step 2 waits for step 1.
        $this->summary($this->people['hrHead'])->assertJsonPath('data.approvals.pending_count', 0);
        $this->summary()
            ->assertJsonPath('data.approvals.pending_count', 0)
            ->assertJsonPath('data.leave_requests.pending_count', 1);
    }

    public function test_an_account_with_no_employee_is_refused(): void
    {
        $this->actingAsRolelessUser();

        $this->getJson('/api/me/summary')->assertForbidden();
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/me/summary')->assertUnauthorized();
    }
}
