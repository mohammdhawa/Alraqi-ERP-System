<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\AttendanceDayState;
use App\Modules\Attendance\Enums\AttendanceRecordStatus;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Support\ShiftRules;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Scheduling\Models\Shift;
use App\Modules\Scheduling\Models\WorkSchedule;
use App\Modules\Scheduling\Services\ScheduleAssigner;
use Database\Seeders\SchedulingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Attendance\Concerns\FakesApprovedLeave;
use Tests\TestCase;

/**
 * Work shifts plan Task 10: the reports and /api/me read shifts (spec §6).
 * 2026-11-14 is a Saturday, 11-20 a Friday. The guard's duty runs 11-14
 * 08:00 → 11-17 08:00; the office day is 09:00–16:00.
 */
class ShiftReportsTest extends TestCase
{
    use FakesApprovedLeave;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-14 06:00:00');
        $this->seed(SchedulingSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function person(string $schedule, ?string $cycleStart = null, array $attributes = []): Employee
    {
        $employee = Employee::factory()->create($attributes);
        User::factory()->create(['employee_id' => $employee->id, 'must_change_password' => false]);
        app(ScheduleAssigner::class)->assign($employee, WorkSchedule::where('code', $schedule)->sole(), '2026-11-14', $cycleStart, User::factory()->create());

        return $employee;
    }

    private function in(Employee $employee, string $at, string $shiftDate, array $extra = []): AttendanceRecord
    {
        return AttendanceRecord::factory()->checkIn()->create(['employee_id' => $employee->id, 'recorded_at' => $at, 'shift_date' => $shiftDate] + $extra);
    }

    private function out(Employee $employee, string $at, string $shiftDate, float $hours): AttendanceRecord
    {
        return AttendanceRecord::factory()->checkOut()->create(['employee_id' => $employee->id, 'recorded_at' => $at, 'shift_date' => $shiftDate, 'work_hours' => $hours]);
    }

    private function grid(Employee $employee, string $from, string $to): TestResponse
    {
        return $this->getJson('/api/attendance/report/employee?'.http_build_query(['employee_id' => $employee->id, 'from' => $from, 'to' => $to]));
    }

    /** @return array<string, array<string, mixed>> */
    private function days(TestResponse $response): array
    {
        return collect($response->assertOk()->json('data.days'))->keyBy('date')->all();
    }

    public function test_a_guards_grid_shows_three_shifts_sharing_one_check_in(): void
    {
        $guard = $this->person('GUARD', '2026-11-14');
        $in = $this->in($guard, '2026-11-14 08:00:00', '2026-11-14');
        $this->out($guard, '2026-11-17 08:00:00', '2026-11-14', 72.0);
        Carbon::setTestNow('2026-11-18 10:00:00');
        $this->actingAsAdmin();

        $days = $this->days($this->grid($guard, '2026-11-14', '2026-11-17'));

        foreach (['2026-11-14', '2026-11-15', '2026-11-16'] as $date) {
            $this->assertSame($in->id, $days[$date]['check_in_id']);
            $this->assertSame(AttendanceDayState::CheckedOut->value, $days[$date]['status']);
            $this->assertEquals(24.0, $days[$date]['work_hours']);
            $this->assertSame('GUARD', $days[$date]['shift']['code']);
            $this->assertTrue($days[$date]['working_day']);
        }
        $this->assertSame(AttendanceDayState::Off->value, $days['2026-11-17']['status']);
        $this->assertNull($days['2026-11-17']['shift']);
        $this->assertEquals(72.0, $this->grid($guard, '2026-11-14', '2026-11-17')->json('data.summary.total_work_hours'));
    }

    public function test_the_daily_board_on_day_two_shows_the_guard_checked_in(): void
    {
        $guard = $this->person('GUARD', '2026-11-14');
        $this->in($guard, '2026-11-14 08:00:00', '2026-11-14');
        Carbon::setTestNow('2026-11-15 12:00:00');
        $this->actingAsAdmin();

        $this->getJson('/api/attendance/report?date=2026-11-15')->assertOk()
            ->assertJsonCount(1, 'data.checked_in')
            ->assertJsonPath('data.checked_in.0.employee.id', $guard->id)
            ->assertJsonPath('data.checked_in.0.shift.code', 'GUARD');
    }

    public function test_a_duty_begun_before_from_is_spread_into_the_range(): void
    {
        $guard = $this->person('GUARD', '2026-11-14');
        $this->in($guard, '2026-11-14 08:00:00', '2026-11-14');
        $this->out($guard, '2026-11-17 08:00:00', '2026-11-14', 72.0);
        Carbon::setTestNow('2026-11-18 10:00:00');
        $this->actingAsAdmin();

        $days = $this->days($this->grid($guard, '2026-11-15', '2026-11-16'));

        $this->assertSame(AttendanceDayState::CheckedOut->value, $days['2026-11-15']['status']);
        $this->assertSame(AttendanceDayState::CheckedOut->value, $days['2026-11-16']['status']);
    }

    public function test_a_missing_check_out_marks_every_shift_of_the_duty(): void
    {
        $guard = $this->person('GUARD', '2026-11-14');
        $this->in($guard, '2026-11-14 08:00:00', '2026-11-14', ['status' => AttendanceRecordStatus::MissingCheckout]);
        Carbon::setTestNow('2026-11-18 10:00:00');
        $this->actingAsAdmin();

        $days = $this->days($this->grid($guard, '2026-11-14', '2026-11-16'));

        foreach (['2026-11-14', '2026-11-15', '2026-11-16'] as $date) {
            $this->assertSame(AttendanceDayState::MissingCheckout->value, $days[$date]['status']);
            $this->assertNull($days[$date]['work_hours']);
        }
    }

    public function test_office_rows_carry_late_and_early_minutes(): void
    {
        $office = $this->person('OFFICE');
        $this->in($office, '2026-11-14 09:16:00', '2026-11-14');
        $this->out($office, '2026-11-14 15:44:00', '2026-11-14', 6.47);
        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->actingAsAdmin();

        $day = $this->days($this->grid($office, '2026-11-14', '2026-11-14'))['2026-11-14'];

        $this->assertSame([16, 16, 388, 0, false], [
            $day['late_minutes'], $day['early_departure_minutes'], $day['minutes_within_shift'], $day['minutes_outside_shift'], $day['outside_schedule'],
        ]);
        $this->assertSame('2026-11-14T09:00:00+03:00', $day['shift']['starts_at']);
    }

    public function test_the_monthly_report_counts_late_and_early(): void
    {
        $office = $this->person('OFFICE');
        $this->in($office, '2026-11-14 09:16:00', '2026-11-14');
        $this->out($office, '2026-11-14 16:00:00', '2026-11-14', 6.73);
        $this->in($office, '2026-11-15 09:30:00', '2026-11-15');
        $this->out($office, '2026-11-15 15:44:00', '2026-11-15', 6.23);
        Carbon::setTestNow('2026-11-16 18:00:00');
        $this->actingAsAdmin();

        $row = $this->getJson('/api/attendance/report/monthly?from=2026-11-14&to=2026-11-16')->assertOk()->json('data.rows.0');

        $this->assertSame([2, 46, 1, 16, 0], [$row['late_count'], $row['late_minutes'], $row['early_departure_count'], $row['early_departure_minutes'], $row['outside_schedule_count']]);
        $this->assertSame([3, 2, 1], [$row['expected_days'], $row['present_days'], $row['absent_days']]);
    }

    public function test_the_arithmetic_still_closes(): void
    {
        $office = $this->person('OFFICE');
        $this->in($office, '2026-11-14 09:00:00', '2026-11-14');
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->actingAsAdmin();

        $row = $this->getJson('/api/attendance/report/monthly?from=2026-11-14&to=2026-11-20')->assertOk()->json('data.rows.0');

        $this->assertSame(6, $row['expected_days'], 'Friday 11-20 is off');
        $this->assertSame($row['expected_days'], $row['present_days'] + $row['on_leave_days'] + $row['excused_days'] + $row['absent_days']);
    }

    public function test_a_shift_is_not_yet_recorded_until_its_end(): void
    {
        $office = $this->person('OFFICE');
        $this->actingAsAdmin();

        Carbon::setTestNow('2026-11-14 15:59:00');
        $this->assertSame(AttendanceDayState::NotYet->value, $this->days($this->grid($office, '2026-11-14', '2026-11-14'))['2026-11-14']['status']);

        Carbon::setTestNow('2026-11-14 16:00:00');
        $this->assertSame(AttendanceDayState::Absent->value, $this->days($this->grid($office, '2026-11-14', '2026-11-14'))['2026-11-14']['status']);
    }

    public function test_absent_today_lists_only_ended_shifts(): void
    {
        $office = $this->person('OFFICE');
        $this->actingAsAdmin();

        Carbon::setTestNow('2026-11-14 12:00:00');
        $this->getJson('/api/attendance/absent-today?date=2026-11-14')->assertOk()->assertJsonCount(0, 'data');

        Carbon::setTestNow('2026-11-14 16:30:00');
        $this->getJson('/api/attendance/absent-today?date=2026-11-14')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee.id', $office->id);
    }

    public function test_fridays_are_off_under_office(): void
    {
        $office = $this->person('OFFICE');
        Carbon::setTestNow('2026-11-21 10:00:00');
        $this->actingAsAdmin();

        $day = $this->days($this->grid($office, '2026-11-20', '2026-11-20'))['2026-11-20'];

        $this->assertSame(AttendanceDayState::Off->value, $day['status']);
        $this->assertFalse($day['working_day']);
    }

    public function test_an_outside_schedule_check_in_is_its_own_flagged_day(): void
    {
        $office = $this->person('OFFICE');
        $this->in($office, '2026-11-20 10:00:00', '2026-11-20', ['outside_schedule' => true]);
        $this->out($office, '2026-11-20 13:00:00', '2026-11-20', 3.0);
        Carbon::setTestNow('2026-11-21 10:00:00');
        $this->actingAsAdmin();

        $day = $this->days($this->grid($office, '2026-11-20', '2026-11-20'))['2026-11-20'];

        $this->assertSame(AttendanceDayState::CheckedOut->value, $day['status']);
        $this->assertTrue($day['outside_schedule']);
        $this->assertSame([180, 0], [$day['minutes_outside_shift'], $day['minutes_within_shift']]);
    }

    public function test_no_shift_is_expected_after_termination(): void
    {
        $office = $this->person('OFFICE', attributes: ['termination_date' => '2026-11-16']);
        Carbon::setTestNow('2026-11-21 10:00:00');
        $this->actingAsAdmin();

        $days = $this->days($this->grid($office, '2026-11-14', '2026-11-19'));

        $this->assertTrue($days['2026-11-16']['working_day']);
        $this->assertFalse($days['2026-11-17']['working_day']);
        $this->assertNull($days['2026-11-17']['shift']);
    }

    public function test_my_summary_shows_todays_and_the_next_shift(): void
    {
        $office = $this->person('OFFICE');
        Carbon::setTestNow('2026-11-19 17:00:00');
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::where('employee_id', $office->id)->sole());

        $this->getJson('/api/me/summary')->assertOk()
            ->assertJsonPath('data.attendance_today.shift.code', 'DAY')
            ->assertJsonPath('data.attendance_today.shift.starts_at', '2026-11-19T09:00:00+03:00')
            ->assertJsonPath('data.attendance_today.next_shift.starts_at', '2026-11-21T09:00:00+03:00');
    }

    /**
     * Final review I3: a check-in outside the schedule on a working date does
     * not satisfy the shift unless its presence overlaps the shift.
     */
    public function test_an_outside_check_in_after_the_shift_does_not_make_the_shift_present(): void
    {
        $office = $this->person('OFFICE');
        $this->in($office, '2026-11-14 16:30:00', '2026-11-14', ['outside_schedule' => true]);
        $this->out($office, '2026-11-14 17:40:00', '2026-11-14', 1.17);
        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->actingAsAdmin();

        $day = $this->days($this->grid($office, '2026-11-14', '2026-11-14'))['2026-11-14'];
        $this->assertSame(AttendanceDayState::Absent->value, $day['status']);
        $this->assertTrue($day['outside_schedule']);
        $this->assertSame([0, 70], [$day['minutes_within_shift'], $day['minutes_outside_shift']]);
        $this->assertSame('2026-11-14T16:30:00+03:00', $day['check_in_time']);

        $row = $this->getJson('/api/attendance/report/monthly?from=2026-11-14&to=2026-11-14')->assertOk()->json('data.rows.0');
        $this->assertSame([1, 0, 1, 1], [$row['expected_days'], $row['present_days'], $row['absent_days'], $row['outside_schedule_count']]);
        $this->assertEquals(1.17, $row['total_work_hours']);
    }

    public function test_an_outside_check_in_before_the_window_that_runs_into_the_shift_is_its_presence(): void
    {
        $office = $this->person('OFFICE');
        $this->in($office, '2026-11-14 07:59:00', '2026-11-14', ['outside_schedule' => true]);
        $this->out($office, '2026-11-14 16:00:00', '2026-11-14', 8.02);
        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->actingAsAdmin();

        $day = $this->days($this->grid($office, '2026-11-14', '2026-11-14'))['2026-11-14'];

        $this->assertSame(AttendanceDayState::CheckedOut->value, $day['status']);
        $this->assertSame([0, 420, 61, true], [$day['late_minutes'], $day['minutes_within_shift'], $day['minutes_outside_shift'], $day['outside_schedule']]);
    }

    // ── Per-shift rules plan Task 4: overtime and short days on every report (spec §4.5) ──

    /** DAY counts overtime past 30 minutes and needs 400 of its 420 minutes; the office does 11-14 to 11-17. */
    private function overtimeOffice(): Employee
    {
        Shift::where('code', 'DAY')->sole()->rules()->where('effective_from', ShiftRules::FIRST_DAY)->sole()
            ->update(['overtime_enabled' => true, 'overtime_min_minutes' => 30, 'required_minutes' => 400]);
        $office = $this->person('OFFICE');
        $this->in($office, '2026-11-14 09:20:00', '2026-11-14');           // within 400 — not short
        $this->out($office, '2026-11-14 16:40:00', '2026-11-14', 7.33);     // 40 after the end — overtime
        $this->in($office, '2026-11-15 09:30:00', '2026-11-15');           // within 390 — short 10
        $this->out($office, '2026-11-15 16:10:00', '2026-11-15', 6.67);     // 10 after — below the minimum
        $this->in($office, '2026-11-16 09:00:00', '2026-11-16', ['status' => AttendanceRecordStatus::MissingCheckout]);
        // 11-17: nothing — absent.
        Carbon::setTestNow('2026-11-18 10:00:00');

        return $office;
    }

    public function test_the_grid_shows_overtime_and_short_minutes(): void
    {
        $office = $this->overtimeOffice();
        $this->actingAsAdmin();

        $days = $this->days($this->grid($office, '2026-11-14', '2026-11-17'));

        $this->assertSame([40, 0], [$days['2026-11-14']['overtime_minutes'], $days['2026-11-14']['short_minutes']]);
        $this->assertSame([0, 10], [$days['2026-11-15']['overtime_minutes'], $days['2026-11-15']['short_minutes']]);
        $this->assertSame([0, 0], [$days['2026-11-16']['overtime_minutes'], $days['2026-11-16']['short_minutes']], 'a missing check-out');
        $this->assertSame([0, 0], [$days['2026-11-17']['overtime_minutes'], $days['2026-11-17']['short_minutes']], 'an absent day');
    }

    public function test_the_daily_board_shows_overtime_and_short_minutes(): void
    {
        $this->overtimeOffice();
        $this->actingAsAdmin();

        $this->getJson('/api/attendance/report?date=2026-11-14')->assertOk()
            ->assertJsonPath('data.checked_out.0.overtime_minutes', 40)
            ->assertJsonPath('data.checked_out.0.short_minutes', 0);
    }

    public function test_the_monthly_report_counts_overtime_and_short_days(): void
    {
        $this->overtimeOffice();
        $this->actingAsAdmin();

        $report = $this->getJson('/api/attendance/report/monthly?from=2026-11-14&to=2026-11-17')->assertOk()->json('data');

        foreach ([$report['rows'][0], $report['totals']] as $counts) {
            $this->assertSame([1, 40, 1, 10], [$counts['overtime_count'], $counts['overtime_minutes'], $counts['short_count'], $counts['short_minutes']]);
        }
    }

    public function test_a_leave_day_with_presence_is_never_short(): void
    {
        config(['attendance.allow_attendance_during_leave' => true]);
        $office = $this->person('OFFICE');
        $this->grantLeave($office, '2026-11-14', '2026-11-14');
        $this->in($office, '2026-11-14 09:00:00', '2026-11-14');
        $this->out($office, '2026-11-14 11:00:00', '2026-11-14', 2.0);
        Carbon::setTestNow('2026-11-15 10:00:00');
        $this->actingAsAdmin();

        $day = $this->days($this->grid($office, '2026-11-14', '2026-11-14'))['2026-11-14'];

        $this->assertSame(AttendanceDayState::CheckedOut->value, $day['status'], 'presence outranks the leave span');
        $this->assertSame(0, $day['short_minutes'], 'spec §4.4: leave is never short');
    }

    public function test_my_days_show_overtime_and_short_minutes(): void
    {
        $office = $this->overtimeOffice();
        Sanctum::actingAs(User::where('employee_id', $office->id)->sole());

        $days = collect($this->getJson('/api/me/attendance/days?from=2026-11-14&to=2026-11-15')->assertOk()->json('data.days'))->keyBy('date');

        $this->assertSame([40, 0], [$days['2026-11-14']['overtime_minutes'], $days['2026-11-14']['short_minutes']]);
        $this->assertSame([0, 10], [$days['2026-11-15']['overtime_minutes'], $days['2026-11-15']['short_minutes']]);
    }
}
