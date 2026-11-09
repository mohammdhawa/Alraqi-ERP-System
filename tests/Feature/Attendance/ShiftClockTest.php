<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\AttendanceRecordStatus;
use App\Modules\Attendance\Enums\RejectionReason;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Auth\Models\Notification;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Scheduling\Models\Shift;
use App\Modules\Scheduling\Models\WorkSchedule;
use App\Modules\Scheduling\Services\ScheduleAssigner;
use App\Shared\Support\NotificationEventCatalogue;
use Database\Seeders\SchedulingSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Work shifts plan Task 11: one shift clock replaces the 17:00 reminder and
 * the 23:59 close (spec §8). The guard's duty runs 11-14 08:00 → 11-17 08:00
 * (closing 12:00); the office day 09:00–16:00 (closing 20:00).
 */
class ShiftClockTest extends TestCase
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

    private function person(?string $schedule = null, ?string $cycleStart = null): Employee
    {
        $employee = Employee::factory()->create();
        User::factory()->create(['employee_id' => $employee->id]);
        if ($schedule !== null) {
            app(ScheduleAssigner::class)->assign($employee, WorkSchedule::where('code', $schedule)->sole(), '2026-11-14', $cycleStart, User::factory()->create());
        }

        return $employee;
    }

    private function in(Employee $employee, string $at, string $shiftDate, array $extra = []): AttendanceRecord
    {
        return AttendanceRecord::factory()->checkIn()->create(['employee_id' => $employee->id, 'recorded_at' => $at, 'shift_date' => $shiftDate] + $extra);
    }

    private function tick(string $at): string
    {
        Carbon::setTestNow($at);
        $this->artisan('attendance:shift-clock', ['--at' => $at])->assertSuccessful();

        return $at;
    }

    private function reminders(): int
    {
        return Notification::where('type', NotificationEventCatalogue::ATTENDANCE_CHECKOUT_REMINDER)->count();
    }

    public function test_a_guard_is_reminded_once_at_08_00_on_day_four(): void
    {
        $this->in($this->person('GUARD', '2026-11-14'), '2026-11-14 08:00:00', '2026-11-14');

        $this->tick('2026-11-17 07:55:00');
        $this->assertSame(0, $this->reminders());

        $this->tick('2026-11-17 08:00:00');
        $this->tick('2026-11-17 08:05:00');
        $this->assertSame(1, $this->reminders());
    }

    public function test_office_is_reminded_at_16_00_and_closed_at_20_00(): void
    {
        $checkIn = $this->in($this->person('OFFICE'), '2026-11-14 09:00:00', '2026-11-14');

        $this->tick('2026-11-14 15:55:00');
        $this->assertSame(0, $this->reminders());

        $this->tick('2026-11-14 16:00:00');
        $this->assertSame(1, $this->reminders());
        $this->assertNotNull($checkIn->fresh()->checkout_reminded_at);

        $this->tick('2026-11-14 19:55:00');
        $this->assertNull($checkIn->fresh()->status);

        $this->tick('2026-11-14 20:00:00');
        $this->assertSame(AttendanceRecordStatus::MissingCheckout, $checkIn->fresh()->status);
    }

    public function test_an_outside_check_in_is_closed_at_the_end_of_its_date_and_never_reminded(): void
    {
        $checkIn = $this->in($this->person('OFFICE'), '2026-11-20 10:00:00', '2026-11-20', ['outside_schedule' => true]);

        $this->tick('2026-11-20 23:55:00');
        $this->assertNull($checkIn->fresh()->status);
        $this->assertSame(0, $this->reminders());

        $this->tick('2026-11-21 00:00:00');
        $this->assertSame(AttendanceRecordStatus::MissingCheckout, $checkIn->fresh()->status);
    }

    public function test_the_open_day_is_reminded_at_17_00_and_closes_at_the_end_of_its_date(): void
    {
        $checkIn = $this->in($this->person(), '2026-11-14 08:00:00', '2026-11-14');

        $this->tick('2026-11-14 17:00:00');
        $this->assertSame(1, $this->reminders());

        $this->tick('2026-11-14 23:55:00');
        $this->assertNull($checkIn->fresh()->status);

        $this->tick('2026-11-15 00:00:00');
        $this->assertSame(AttendanceRecordStatus::MissingCheckout, $checkIn->fresh()->status);
    }

    public function test_a_night_shift_is_reminded_at_06_00_and_closed_at_10_00_the_next_day(): void
    {
        $night = Shift::create(['code' => 'NIGHT', 'name' => 'ليلية', 'starts_at' => '22:00', 'duration_minutes' => 480]);
        WorkSchedule::createWithDays(['code' => 'NIGHTS', 'name' => 'ليلي', 'kind' => 1, 'cycle_days' => 7], array_fill(0, 7, $night->id));
        $checkIn = $this->in($this->person('NIGHTS'), '2026-11-14 21:45:00', '2026-11-14');

        $this->tick('2026-11-15 06:00:00');
        $this->assertSame(1, $this->reminders());

        $this->tick('2026-11-15 10:00:00');
        $this->assertSame(AttendanceRecordStatus::MissingCheckout, $checkIn->fresh()->status);
    }

    public function test_only_people_still_checked_in_are_reminded_with_the_same_words(): void
    {
        $stillIn = $this->person('OFFICE');
        $this->in($stillIn, '2026-11-14 09:00:00', '2026-11-14');
        $done = $this->person('OFFICE');
        $this->in($done, '2026-11-14 09:00:00', '2026-11-14');
        AttendanceRecord::factory()->checkOut()->create(['employee_id' => $done->id, 'recorded_at' => '2026-11-14 15:30:00', 'shift_date' => '2026-11-14']);
        $refused = $this->person('OFFICE');
        $this->in($refused, '2026-11-14 09:00:00', '2026-11-14', ['rejected_at' => now(), 'rejection_reason' => RejectionReason::Other]);
        $disabled = $this->person('OFFICE');
        $this->in($disabled, '2026-11-14 09:00:00', '2026-11-14');
        User::where('employee_id', $disabled->id)->update(['is_active' => false]);

        $this->tick('2026-11-14 16:00:00');

        $notice = Notification::sole();
        $this->assertSame($stillIn->id, (int) $notice->user->employee_id);
        $this->assertSame('تذكير بتسجيل الانصراف', $notice->title);
        $this->assertSame('لقد سجلت حضورك اليوم ولم تسجل انصرافك بعد. يرجى تسجيل الانصراف.', $notice->body);
    }

    public function test_the_close_invents_nothing_notifies_nobody_and_leaves_refusals_alone(): void
    {
        $open = $this->in($this->person('OFFICE'), '2026-11-14 09:00:00', '2026-11-14', ['checkout_reminded_at' => '2026-11-14 16:00:00']);
        $refused = $this->in($this->person('OFFICE'), '2026-11-14 09:00:00', '2026-11-14', ['rejected_at' => now(), 'rejection_reason' => RejectionReason::Location]);

        $this->tick('2026-11-14 20:00:00');

        $this->assertSame(AttendanceRecordStatus::MissingCheckout, $open->fresh()->status);
        $this->assertNull($open->fresh()->work_hours);
        $this->assertSame(0, AttendanceRecord::checkOuts()->count(), 'no check-out is invented');
        $this->assertNull($refused->fresh()->status);
        $this->assertSame(0, Notification::count(), 'nobody is notified at the close');
    }

    public function test_running_twice_changes_nothing(): void
    {
        $checkIn = $this->in($this->person('OFFICE'), '2026-11-14 09:00:00', '2026-11-14');

        $this->tick('2026-11-14 20:00:00');
        $this->artisan('attendance:shift-clock', ['--at' => '2026-11-14 20:00:00'])
            ->expectsOutputToContain('Reminded 0, closed 0')->assertSuccessful();

        $this->assertSame(AttendanceRecordStatus::MissingCheckout, $checkIn->fresh()->status);
    }

    public function test_it_runs_every_five_minutes_and_the_old_commands_are_gone(): void
    {
        $events = collect(app(Schedule::class)->events());
        $event = fn (string $command): ?Event => $events->first(fn (Event $e): bool => str_ends_with((string) $e->command, $command));

        $this->assertSame('*/5 * * * *', $event('attendance:shift-clock')?->expression);
        $this->assertTrue($event('attendance:shift-clock')->withoutOverlapping);
        $this->assertNull($event('attendance:checkout-reminder'));
        $this->assertNull($event('attendance:auto-close'));
        $this->assertSame('0 3 * * *', $event('attendance:purge-selfies')?->expression);
    }
}
