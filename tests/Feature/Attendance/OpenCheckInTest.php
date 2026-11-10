<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\AttendanceRecordStatus;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Services\OpenCheckIns;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Work shifts, step 1 (plan Task 2): a check-out closes the employee's open
 * check-in — judged on moments, not on the date — and a check-in stays open
 * until its close. Until schedules exist the close is the end of its shift
 * date, which is today's "same day" rule exactly.
 */
class OpenCheckInTest extends TestCase
{
    use RefreshDatabase;

    private function openCheckIns(): OpenCheckIns
    {
        return app(OpenCheckIns::class);
    }

    private function at(string $moment): Carbon
    {
        return Carbon::parse($moment, (string) config('attendance.timezone'));
    }

    public function test_for_check_out_finds_a_flagged_check_in_on_its_date(): void
    {
        $employee = Employee::factory()->create();
        $checkIn = AttendanceRecord::factory()->checkIn()->create([
            'employee_id' => $employee->id, 'recorded_at' => '2026-06-21 08:00:00', 'status' => AttendanceRecordStatus::MissingCheckout,
        ]);

        $this->assertSame($checkIn->id, $this->openCheckIns()->forCheckOut($employee, $this->at('2026-06-21 16:00:00'))?->id);
    }

    public function test_for_check_out_finds_nothing_after_the_close(): void
    {
        $employee = Employee::factory()->create();
        AttendanceRecord::factory()->checkIn()->create(['employee_id' => $employee->id, 'recorded_at' => '2026-06-21 08:00:00']);

        $this->assertNull($this->openCheckIns()->forCheckOut($employee, $this->at('2026-06-22 00:30:00')));
    }

    public function test_blocking_is_cleared_by_a_check_out(): void
    {
        $employee = Employee::factory()->create();
        $checkIn = AttendanceRecord::factory()->checkIn()->create(['employee_id' => $employee->id, 'recorded_at' => '2026-06-21 08:00:00']);

        $this->assertSame($checkIn->id, $this->openCheckIns()->blocking($employee, $this->at('2026-06-21 12:00:00'))?->id);

        AttendanceRecord::factory()->checkOut()->create(['employee_id' => $employee->id, 'recorded_at' => '2026-06-21 16:00:00']);

        $this->assertNull($this->openCheckIns()->blocking($employee, $this->at('2026-06-21 17:00:00')));
    }

    public function test_close_of_is_the_duty_s_close(): void
    {
        // 2026-06-21 is a Sunday: the default OFFICE day, 09:00–16:00, closes at 20:00.
        $checkIn = AttendanceRecord::factory()->checkIn()->create(['recorded_at' => '2026-06-21 08:00:00']);

        $this->assertSame('2026-06-21 20:00:00', $this->openCheckIns()->closeOf($checkIn)->format('Y-m-d H:i:s'));
    }

    public function test_an_outside_schedule_check_in_closes_at_the_end_of_its_date(): void
    {
        $checkIn = AttendanceRecord::factory()->checkIn()->create(['recorded_at' => '2026-06-19 10:00:00', 'outside_schedule' => true]);

        $this->assertSame('2026-06-19 23:59:59', $this->openCheckIns()->closeOf($checkIn)->format('Y-m-d H:i:s'));
    }

    public function test_a_check_out_takes_its_check_ins_shift_date(): void
    {
        $employee = Employee::factory()->create();
        AttendanceRecord::factory()->checkIn()->create([
            'employee_id' => $employee->id, 'recorded_at' => '2026-06-21 23:00:00', 'shift_date' => '2026-06-21', 'outside_schedule' => true,
        ]);

        $this->assertSame('2026-06-21', $this->openCheckIns()->forCheckOut($employee, $this->at('2026-06-21 23:59:30'))?->shift_date->toDateString());
    }
}
