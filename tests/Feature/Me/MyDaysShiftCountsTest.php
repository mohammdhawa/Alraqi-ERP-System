<?php

declare(strict_types=1);

namespace Tests\Feature\Me;

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
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The month counts of the four figures and each day's `check_in_shift_date`
 * on `/api/me/attendance/days` and HR's grid (work shifts piece 4, app spec
 * §4.2–§4.3). 2026-11-14 is a Saturday; the office day is 09:00–16:00.
 */
class MyDaysShiftCountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        Carbon::setTestNow('2026-11-14 06:00:00');
        $this->seed(SchedulingSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function person(string $schedule, ?string $cycleStart = null): Employee
    {
        $employee = Employee::factory()->create();
        User::factory()->create(['employee_id' => $employee->id, 'must_change_password' => false]);
        app(ScheduleAssigner::class)->assign($employee, WorkSchedule::where('code', $schedule)->sole(), '2026-11-14', $cycleStart, User::factory()->create());

        return $employee;
    }

    private function pair(Employee $employee, string $in, string $out, string $shiftDate, float $hours): void
    {
        AttendanceRecord::factory()->checkIn()->create(['employee_id' => $employee->id, 'recorded_at' => $in, 'shift_date' => $shiftDate]);
        AttendanceRecord::factory()->checkOut()->create(['employee_id' => $employee->id, 'recorded_at' => $out, 'shift_date' => $shiftDate, 'work_hours' => $hours]);
    }

    /** @return array<string, mixed> */
    private function myDays(Employee $employee, string $from, string $to): array
    {
        Carbon::setTestNow('2026-11-17 12:00:00');
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::where('employee_id', $employee->id)->sole());

        return $this->getJson('/api/me/attendance/days?'.http_build_query(['from' => $from, 'to' => $to]))->assertOk()->json('data');
    }

    public function test_summary_carries_the_four_figures_counts(): void
    {
        Shift::where('code', 'DAY')->sole()->rules()->where('effective_from', ShiftRules::FIRST_DAY)->sole()
            ->update(['overtime_enabled' => true, 'overtime_min_minutes' => 30]);
        $office = $this->person('OFFICE');
        $this->pair($office, '2026-11-14 09:16:00', '2026-11-14 16:00:00', '2026-11-14', 6.73);
        $this->pair($office, '2026-11-15 08:55:00', '2026-11-15 16:45:00', '2026-11-15', 7.83);

        $summary = $this->myDays($office, '2026-11-14', '2026-11-15')['summary'];

        $this->assertSame(1, $summary['late_count']);
        $this->assertSame(16, $summary['late_minutes']);
        $this->assertSame(1, $summary['overtime_count']);
        $this->assertSame(45, $summary['overtime_minutes']);
        $this->assertSame(0, $summary['early_departure_count']);
        $this->assertSame(0, $summary['early_departure_minutes']);
        $this->assertSame(1, $summary['short_count']);
        $this->assertSame(16, $summary['short_minutes']);
        $this->assertSame(0, $summary['outside_schedule_count']);
    }

    public function test_guard_days_share_the_duty_shift_date(): void
    {
        $guard = $this->person('GUARD', '2026-11-14');
        $this->pair($guard, '2026-11-14 07:55:00', '2026-11-17 08:05:00', '2026-11-14', 72.17);

        $days = collect($this->myDays($guard, '2026-11-14', '2026-11-17')['days'])->keyBy('date');

        $this->assertSame('2026-11-14', $days['2026-11-14']['check_in_shift_date']);
        $this->assertSame('2026-11-14', $days['2026-11-15']['check_in_shift_date']);
        $this->assertSame('2026-11-14', $days['2026-11-16']['check_in_shift_date']);
        $this->assertNull($days['2026-11-17']['check_in_shift_date']);
    }

    public function test_hr_grid_carries_the_same_keys(): void
    {
        $office = $this->person('OFFICE');
        $this->pair($office, '2026-11-14 09:00:00', '2026-11-14 16:00:00', '2026-11-14', 7.0);
        Carbon::setTestNow('2026-11-17 12:00:00');
        $this->actingAsAdmin();

        $data = $this->getJson('/api/attendance/report/employee?'.http_build_query(['employee_id' => $office->id, 'from' => '2026-11-14', 'to' => '2026-11-15']))
            ->assertOk()->json('data');

        foreach (['late_count', 'late_minutes', 'early_departure_count', 'early_departure_minutes', 'overtime_count', 'overtime_minutes', 'short_count', 'short_minutes', 'outside_schedule_count'] as $key) {
            $this->assertArrayHasKey($key, $data['summary'], $key);
        }
        $this->assertSame('2026-11-14', $data['days'][0]['check_in_shift_date']);
    }
}
