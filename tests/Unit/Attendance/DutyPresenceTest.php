<?php

declare(strict_types=1);

namespace Tests\Unit\Attendance;

use App\Modules\Attendance\Support\Duty;
use App\Modules\Attendance\Support\DutyPresence;
use App\Modules\Attendance\Support\ExpectedShift;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Work shifts plan Task 9: a duty's presence spread over its shifts (spec
 * §6.1), with 15 minutes' grace. The guard's duty is 11-14 08:00 → 11-17
 * 08:00; the office day 11-14 09:00–16:00.
 */
class DutyPresenceTest extends TestCase
{
    private const GRACE = 15;

    private function at(string $moment): CarbonImmutable
    {
        return CarbonImmutable::parse($moment, 'Asia/Damascus');
    }

    private function shift(string $date, string $start, int $minutes, bool $open = false): ExpectedShift
    {
        $startsAt = $this->at("{$date} {$start}");

        return new ExpectedShift($date, $startsAt, $startsAt->addMinutes($minutes), $open ? 'OPEN' : 'S', 'وردية', $open, 'assignment');
    }

    private function guardDuty(): Duty
    {
        return Duty::of([
            $this->shift('2026-11-14', '08:00', 1440),
            $this->shift('2026-11-15', '08:00', 1440),
            $this->shift('2026-11-16', '08:00', 1440),
        ], 60, 240);
    }

    private function officeDay(): Duty
    {
        return Duty::of([$this->shift('2026-11-14', '09:00', 420)], 60, 240);
    }

    private function split(Duty $duty, ?string $in, ?string $out, string $now = '2026-11-20 00:00:00'): array
    {
        return DutyPresence::split($duty, $in === null ? null : $this->at($in), $out === null ? null : $this->at($out), $this->at($now), self::GRACE);
    }

    public function test_a_full_guard_duty_is_three_present_shifts_of_24_hours(): void
    {
        $presence = $this->split($this->guardDuty(), '2026-11-14 08:00:00', '2026-11-17 08:00:00');

        $this->assertSame(['2026-11-14', '2026-11-15', '2026-11-16'], array_keys($presence));
        foreach ($presence as $shift) {
            $this->assertTrue($shift->present);
            $this->assertSame([24.0, 0, 0, 1440, 0], [$shift->workHours, $shift->lateMinutes, $shift->earlyDepartureMinutes, $shift->minutesWithin, $shift->minutesOutside]);
        }
    }

    public function test_arriving_on_day_two_leaves_day_one_absent_and_day_two_late(): void
    {
        $presence = $this->split($this->guardDuty(), '2026-11-15 10:00:00', '2026-11-17 08:00:00');

        $this->assertFalse($presence['2026-11-14']->present);
        $this->assertSame(0.0, $presence['2026-11-14']->workHours);
        $this->assertTrue($presence['2026-11-15']->present);
        $this->assertSame(120, $presence['2026-11-15']->lateMinutes);
        $this->assertSame(0, $presence['2026-11-16']->lateMinutes);
    }

    public function test_leaving_on_day_three_is_an_early_departure(): void
    {
        $presence = $this->split($this->guardDuty(), '2026-11-14 08:00:00', '2026-11-16 20:00:00');

        $this->assertSame([720, 720], [$presence['2026-11-16']->earlyDepartureMinutes, $presence['2026-11-16']->minutesWithin]);
        $this->assertSame(0, $presence['2026-11-15']->earlyDepartureMinutes);
    }

    public function test_09_15_is_on_time_and_09_16_is_sixteen_minutes_late(): void
    {
        $this->assertSame(0, $this->split($this->officeDay(), '2026-11-14 09:15:00', '2026-11-14 16:00:00')['2026-11-14']->lateMinutes);
        $this->assertSame(16, $this->split($this->officeDay(), '2026-11-14 09:16:00', '2026-11-14 16:00:00')['2026-11-14']->lateMinutes);
    }

    public function test_15_44_is_sixteen_minutes_early(): void
    {
        $this->assertSame(0, $this->split($this->officeDay(), '2026-11-14 09:00:00', '2026-11-14 15:45:00')['2026-11-14']->earlyDepartureMinutes);
        $this->assertSame(16, $this->split($this->officeDay(), '2026-11-14 09:00:00', '2026-11-14 15:44:00')['2026-11-14']->earlyDepartureMinutes);
    }

    public function test_arriving_at_08_00_counts_sixty_minutes_outside(): void
    {
        $day = $this->split($this->officeDay(), '2026-11-14 08:00:00', '2026-11-14 16:00:00')['2026-11-14'];

        $this->assertSame([420, 60, 8.0, 0], [$day->minutesWithin, $day->minutesOutside, $day->workHours, $day->lateMinutes]);
    }

    public function test_staying_late_counts_outside_on_the_last_shift(): void
    {
        $presence = $this->split($this->guardDuty(), '2026-11-14 08:00:00', '2026-11-17 09:30:00');

        $this->assertSame(90, $presence['2026-11-16']->minutesOutside);
        $this->assertSame(0, $presence['2026-11-14']->minutesOutside);
        $this->assertSame(73.5, array_sum(array_map(fn ($p) => $p->workHours, $presence)));
    }

    public function test_an_open_check_in_runs_to_now_with_no_early_departure(): void
    {
        $day = $this->split($this->officeDay(), '2026-11-14 09:00:00', null, now: '2026-11-14 12:00:00')['2026-11-14'];

        $this->assertSame([180, 0, 3.0], [$day->minutesWithin, $day->earlyDepartureMinutes, $day->workHours]);
    }

    public function test_no_check_in_is_no_presence(): void
    {
        $day = $this->split($this->officeDay(), null, null)['2026-11-14'];

        $this->assertFalse($day->present);
        $this->assertSame([0, 0, 0, 0.0], [$day->lateMinutes, $day->minutesWithin, $day->minutesOutside, $day->workHours]);
    }

    public function test_the_open_day_has_no_late_or_outside(): void
    {
        $open = Duty::of([$this->shift('2026-11-14', '00:00', 1439, open: true)], 60, 240);

        $day = $this->split($open, '2026-11-14 11:00:00', '2026-11-14 15:00:00')['2026-11-14'];

        $this->assertSame([0, 0, 0, 240, 4.0], [$day->lateMinutes, $day->earlyDepartureMinutes, $day->minutesOutside, $day->minutesWithin, $day->workHours]);
    }
}
