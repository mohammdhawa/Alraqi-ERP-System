<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Attendance\Enums\AttendanceRecordStatus;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Scheduling\Enums\ShiftChangeOrigin;
use App\Modules\Scheduling\Exceptions\SchedulingException;
use App\Modules\Scheduling\Models\Shift;
use App\Modules\Scheduling\Models\ShiftChange;
use App\Modules\Scheduling\Models\WorkSchedule;
use App\Modules\Scheduling\Services\CheckInRematch;
use App\Modules\Scheduling\Services\ScheduleAssigner;
use Database\Seeders\SchedulingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Work shifts piece 2, plan Task 3: the check-ins a change touches are
 * matched again by the check-in rule, as if they had happened under the new
 * shift (spec §5.3). 2026-11-14 is a Saturday; the office day is 09:00–16:00.
 */
class CheckInRematchTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-13 10:00:00');
        $this->seed(SchedulingSeeder::class);
        $this->employee = Employee::factory()->create();
        app(ScheduleAssigner::class)->assign($this->employee, WorkSchedule::where('code', 'OFFICE')->sole(), '2026-11-14', null, User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function shift(string $code, string $startsAt, int $minutes): Shift
    {
        return Shift::firstOrCreate(['code' => $code], ['name' => $code, 'starts_at' => $startsAt, 'duration_minutes' => $minutes, 'is_active' => true]);
    }

    private function change(string $date, ?Shift $shift): ShiftChange
    {
        return ShiftChange::create(['employee_id' => $this->employee->id, 'date' => $date, 'shift_id' => $shift?->id, 'origin' => ShiftChangeOrigin::Hr, 'reason' => 'تكليف']);
    }

    /** @param  array<string, mixed>  $extra */
    private function in(string $at, string $shiftDate, bool $outside = false, array $extra = []): AttendanceRecord
    {
        return AttendanceRecord::factory()->checkIn()->create(['employee_id' => $this->employee->id, 'recorded_at' => $at, 'shift_date' => $shiftDate, 'outside_schedule' => $outside] + $extra);
    }

    private function out(string $at, string $shiftDate): AttendanceRecord
    {
        return AttendanceRecord::factory()->checkOut()->create(['employee_id' => $this->employee->id, 'recorded_at' => $at, 'shift_date' => $shiftDate]);
    }

    private function rematch(string $from, string $to): void
    {
        app(CheckInRematch::class)->run($this->employee, $from, $to);
    }

    public function test_an_outside_check_in_becomes_the_night_shifts(): void
    {
        // Under OFFICE an outside check-in closes at the end of its date, so
        // its check-out was before midnight and carries its shift date.
        $in = $this->in('2026-11-15 21:30:00', '2026-11-15', outside: true);
        $out = $this->out('2026-11-15 23:30:00', '2026-11-15');
        $this->change('2026-11-15', $this->shift('NIGHT', '22:00', 480));

        $this->rematch('2026-11-15', '2026-11-15');

        $this->assertFalse($in->fresh()->outside_schedule);
        $this->assertSame('2026-11-15', $in->fresh()->shift_date->toDateString());
        $this->assertSame('2026-11-15', $out->fresh()->shift_date->toDateString());
    }

    public function test_removing_the_change_puts_it_back_outside(): void
    {
        $in = $this->in('2026-11-15 21:30:00', '2026-11-15');
        $this->change('2026-11-15', $this->shift('NIGHT', '22:00', 480))->delete();

        $this->rematch('2026-11-15', '2026-11-15');

        $this->assertTrue($in->fresh()->outside_schedule);
        $this->assertSame('2026-11-15', $in->fresh()->shift_date->toDateString());
    }

    public function test_a_check_out_after_the_new_close_is_refused(): void
    {
        $this->in('2026-11-15 04:30:00', '2026-11-15', outside: true);
        $this->out('2026-11-15 10:00:00', '2026-11-15');
        $this->change('2026-11-15', $this->shift('EARLY', '03:00', 120));

        $this->expectExceptionObject(new SchedulingException('يقع تسجيل انصراف 2026-11-15 بعد إغلاق الوردية الجديدة.'));
        $this->rematch('2026-11-15', '2026-11-15');
    }

    public function test_two_check_ins_on_one_duty_are_refused(): void
    {
        $this->in('2026-11-15 08:30:00', '2026-11-15');
        $this->out('2026-11-15 16:00:00', '2026-11-15');
        $this->in('2026-11-15 23:00:00', '2026-11-16', outside: true);
        $this->change('2026-11-15', $this->shift('LONG24', '08:00', 1440));

        $this->expectExceptionObject(new SchedulingException('يتعارض هذا التعديل مع تسجيل حضور في 2026-11-15.'));
        $this->rematch('2026-11-15', '2026-11-15');
    }

    public function test_a_night_change_on_the_last_date_carries_its_check_out(): void
    {
        // A check-in after midnight was outside on its own date (11-20, a
        // Friday off); the night change on 11-19, the range's last date,
        // gives it to the 11-19 duty, and its check-out moves with it.
        $in = $this->in('2026-11-20 00:30:00', '2026-11-20', outside: true);
        $out = $this->out('2026-11-20 05:00:00', '2026-11-20');
        $this->change('2026-11-19', $this->shift('NIGHT', '22:00', 480));

        $this->rematch('2026-11-19', '2026-11-19');

        $this->assertFalse($in->fresh()->outside_schedule);
        $this->assertSame('2026-11-19', $in->fresh()->shift_date->toDateString());
        $this->assertSame('2026-11-19', $out->fresh()->shift_date->toDateString());
    }

    public function test_a_missing_flag_clears_when_the_new_close_is_ahead(): void
    {
        Carbon::setTestNow('2026-11-15 21:00:00');
        $in = $this->in('2026-11-15 09:00:00', '2026-11-15', extra: ['status' => AttendanceRecordStatus::MissingCheckout, 'checkout_reminded_at' => '2026-11-15 16:00:00']);
        $this->change('2026-11-15', $this->shift('LONG14', '08:00', 840));

        $this->rematch('2026-11-15', '2026-11-15');

        $this->assertNull($in->fresh()->status);
        $this->assertNull($in->fresh()->checkout_reminded_at);
    }

    public function test_refused_check_ins_keep_their_date(): void
    {
        $refused = $this->in('2026-11-15 21:30:00', '2026-11-15', outside: true, extra: ['rejected_at' => '2026-11-15 22:00:00']);
        $this->change('2026-11-14', $this->shift('NIGHT', '22:00', 480));

        $this->rematch('2026-11-14', '2026-11-15');

        $this->assertSame('2026-11-15', $refused->fresh()->shift_date->toDateString());
        $this->assertTrue($refused->fresh()->outside_schedule);
    }
}
