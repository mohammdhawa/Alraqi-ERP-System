<?php

declare(strict_types=1);

namespace Tests\Unit\Attendance;

use App\Modules\Attendance\Support\Duty;
use App\Modules\Attendance\Support\DutyPresence;
use App\Modules\Attendance\Support\ExpectedShift;
use App\Modules\Attendance\Support\ShiftPresence;
use App\Modules\Attendance\Support\ShiftRules;
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

    private function shift(string $date, string $start, int $minutes): ExpectedShift
    {
        $startsAt = $this->at("{$date} {$start}");

        return new ExpectedShift($date, $startsAt, $startsAt->addMinutes($minutes), 'S', 'وردية', 'assignment', null,
            new ShiftRules(self::GRACE, self::GRACE, 60, 240, false, 0, $minutes));
    }

    private function guardDuty(): Duty
    {
        return Duty::of([
            $this->shift('2026-11-14', '08:00', 1440),
            $this->shift('2026-11-15', '08:00', 1440),
            $this->shift('2026-11-16', '08:00', 1440),
        ]);
    }

    private function officeDay(): Duty
    {
        return Duty::of([$this->shift('2026-11-14', '09:00', 420)]);
    }

    private function split(Duty $duty, ?string $in, ?string $out, string $now = '2026-11-20 00:00:00'): array
    {
        return DutyPresence::split($duty, $in === null ? null : $this->at($in), $out === null ? null : $this->at($out), $this->at($now));
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

    // ── Per-shift rules plan Task 4: two graces, overtime, short days (spec §4) ──

    /** The office day 11-14 09:00–16:00 under the given rules. */
    private function officeUnder(ShiftRules $rules): Duty
    {
        $startsAt = $this->at('2026-11-14 09:00');

        return Duty::of([new ExpectedShift('2026-11-14', $startsAt, $startsAt->addMinutes(420), 'DAY', 'دوام', 'assignment', null, $rules)]);
    }

    private function officeDayOn(string $in, ?string $out, ShiftRules $rules): ShiftPresence
    {
        return $this->split($this->officeUnder($rules), "2026-11-14 {$in}", $out === null ? null : "2026-11-14 {$out}", '2026-11-14 23:00:00')['2026-11-14'];
    }

    private static function rules(int $late = 15, int $early = 15, bool $overtime = false, int $min = 0, int $required = 420): ShiftRules
    {
        return new ShiftRules($late, $early, 60, 240, $overtime, $min, $required);
    }

    public function test_overtime_is_zero_when_the_shift_does_not_count_it(): void
    {
        $this->assertSame(0, $this->officeDayOn('09:00:00', '16:40:00', self::rules())->overtimeMinutes);
    }

    public function test_overtime_counts_all_minutes_after_the_end_once_the_minimum_is_reached(): void
    {
        $rules = self::rules(overtime: true, min: 30);

        $this->assertSame(0, $this->officeDayOn('09:00:00', '16:29:00', $rules)->overtimeMinutes);
        $this->assertSame(30, $this->officeDayOn('09:00:00', '16:30:00', $rules)->overtimeMinutes);
        $this->assertSame(40, $this->officeDayOn('09:00:00', '16:40:00', $rules)->overtimeMinutes);
    }

    public function test_a_zero_minimum_counts_any_minute_after_the_end(): void
    {
        $this->assertSame(1, $this->officeDayOn('09:00:00', '16:01:00', self::rules(overtime: true))->overtimeMinutes);
    }

    public function test_an_open_check_in_has_no_overtime(): void
    {
        $this->assertSame(0, $this->officeDayOn('09:00:00', null, self::rules(overtime: true))->overtimeMinutes);
    }

    public function test_arriving_early_is_never_overtime(): void
    {
        $this->assertSame(0, $this->officeDayOn('08:00:00', '16:00:00', self::rules(overtime: true))->overtimeMinutes);
    }

    public function test_a_day_is_short_by_the_minutes_within_the_shift_below_the_required(): void
    {
        $this->assertSame(20, $this->officeDayOn('09:20:00', '16:00:00', self::rules())->shortMinutes);
        $this->assertSame(0, $this->officeDayOn('09:20:00', '16:00:00', self::rules(required: 390))->shortMinutes, 'within 400 ≥ 390');
    }

    public function test_arriving_early_does_not_make_up_for_leaving_early(): void
    {
        $this->assertSame(60, $this->officeDayOn('08:00:00', '15:00:00', self::rules())->shortMinutes);
    }

    public function test_an_open_check_in_is_never_short(): void
    {
        $this->assertSame(0, $this->officeDayOn('10:00:00', null, self::rules())->shortMinutes);
    }

    public function test_late_and_early_are_judged_by_their_own_graces(): void
    {
        $rules = self::rules(late: 5, early: 20);

        $this->assertSame(6, $this->officeDayOn('09:06:00', '16:00:00', $rules)->lateMinutes);
        $this->assertSame(0, $this->officeDayOn('09:00:00', '15:45:00', $rules)->earlyDepartureMinutes);
        $this->assertSame(21, $this->officeDayOn('09:00:00', '15:39:00', $rules)->earlyDepartureMinutes);
    }

    public function test_a_duty_counts_overtime_after_its_last_shift(): void
    {
        $at = fn (string $m): CarbonImmutable => $this->at($m);
        $mk = fn (string $date, ShiftRules $r): ExpectedShift => new ExpectedShift($date, $at("{$date} 08:00"), $at("{$date} 08:00")->addDay(), 'G', 'حراسة', 'assignment', null, $r);
        $duty = Duty::of([
            $mk('2026-11-14', new ShiftRules(15, 15, 60, 240, false, 0, 1440)),
            $mk('2026-11-15', new ShiftRules(15, 15, 60, 240, false, 0, 1440)),
            $mk('2026-11-16', new ShiftRules(15, 15, 60, 240, true, 30, 1440)),
        ]);

        $presence = DutyPresence::split($duty, $at('2026-11-14 08:00'), $at('2026-11-17 08:45'), $at('2026-11-18 00:00'));

        $this->assertSame([0, 0, 45], [$presence['2026-11-14']->overtimeMinutes, $presence['2026-11-15']->overtimeMinutes, $presence['2026-11-16']->overtimeMinutes]);
    }

    public function test_seconds_do_not_count_a_full_shift_as_a_minute_short(): void
    {
        // A tap at 09:00:32 reads 09:00 on every screen; it is 09:00 here too.
        $day = $this->split($this->officeDay(), '2026-11-14 09:00:32', '2026-11-14 16:00:42')['2026-11-14'];

        $this->assertSame([420, 0, 0, 0, 7.0], [$day->minutesWithin, $day->shortMinutes, $day->lateMinutes, $day->earlyDepartureMinutes, $day->workHours]);
    }

    public function test_a_check_out_in_the_last_minute_is_one_minute_short_as_it_reads(): void
    {
        // 15:59:10 reads 15:59: one minute short, matching the screen.
        $day = $this->split($this->officeDay(), '2026-11-14 09:00:50', '2026-11-14 15:59:10')['2026-11-14'];

        $this->assertSame([419, 1], [$day->minutesWithin, $day->shortMinutes]);
    }
}
