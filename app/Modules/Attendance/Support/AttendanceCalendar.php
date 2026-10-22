<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * The attendance calendar — legacy's AttendanceService configuration
 * accessors and working-day arithmetic, in one place (work order §4.5).
 *
 * Every attendance rule and, later, every leave deduction reads its days from
 * here, so the two can never disagree about what a working day is.
 *
 * THE FALLBACKS ARE THE SHIPPED VALUES. Legacy's code fell back to 22:00 for
 * the day's close and 06:00–17:00 for the window while its config said 23:59
 * and open — so a missing key silently changed policy. Here a missing or
 * broken setting lands on exactly what config/attendance.php ships (Q9, Q10).
 */
final class AttendanceCalendar
{
    /** No weekly day off (Q8): every day, as Carbon dayOfWeek integers. */
    public const FULL_WEEK = [0, 1, 2, 3, 4, 5, 6];

    public function timezone(): string
    {
        return (string) (config('attendance.timezone') ?: config('app.timezone'));
    }

    /**
     * The working days. Anything that is not a non-empty list falls back to
     * the full week: an empty list is never a deliberate policy — it would mean
     * nobody may check in and no leave is ever charged.
     *
     * @return array<int, int>
     */
    public function workingDays(): array
    {
        $configured = config('attendance.working_days');

        if (! is_array($configured) || $configured === []) {
            return self::FULL_WEEK;
        }

        return array_map('intval', array_values($configured));
    }

    public function isWorkingDay(CarbonInterface $day): bool
    {
        return in_array($day->dayOfWeek, $this->workingDays(), true);
    }

    /**
     * A day that consumes leave balance and on which attendance is expected.
     *
     * THE ONE HOOK FOR A HOLIDAY CALENDAR. Today a chargeable day is a working
     * day; there is no holiday table (Q8, 2026-10-16). When one is added —
     * deferred to before Stage 4 payroll — it is excluded here, and attendance
     * and leave inherit it together.
     */
    public function isChargeableDay(CarbonInterface $day): bool
    {
        return $this->isWorkingDay($day);
    }

    /**
     * Chargeable days in an inclusive span; 0 when the span is empty or
     * backwards.
     *
     * `$skip` (keyed by Y-m-d) leaves dates out — how the monthly report keeps
     * a day the employee actually worked from also counting as a leave day
     * (legacy's chargeableDaysExcluding).
     *
     * @param  array<string, mixed>  $skip
     */
    public function workingDaysBetween(CarbonInterface|string $start, CarbonInterface|string $end, array $skip = []): int
    {
        $cursor = $this->dayOf($start);
        $last = $this->dayOf($end);

        $count = 0;
        for (; $cursor->lte($last); $cursor->addDay()) {
            $count += $this->isChargeableDay($cursor) && ! isset($skip[$cursor->toDateString()]) ? 1 : 0;
        }

        return $count;
    }

    /** Today's date (Y-m-d) in the attendance timezone — the single source of "today". */
    public function today(): string
    {
        return Carbon::now($this->timezone())->toDateString();
    }

    /** A client-supplied moment, in the attendance timezone. */
    public function parse(string $value): Carbon
    {
        return Carbon::parse($value)->setTimezone($this->timezone());
    }

    public function checkInStart(): string
    {
        return (string) (config('attendance.check_in.start') ?? '');
    }

    public function checkInEnd(): string
    {
        return (string) (config('attendance.check_in.end') ?? '');
    }

    /** "H:i" on the same day as $moment. */
    public function timeOn(CarbonInterface $moment, string $time): Carbon
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');

        return Carbon::instance($moment)->setTime((int) $hour, (int) $minute);
    }

    public function minCheckoutGapMinutes(): int
    {
        return max(0, (int) config('attendance.min_checkout_gap_minutes', 60));
    }

    /** Minutes a recorded moment may run ahead of the server (parity audit S-6). */
    public function maxClockAheadMinutes(): int
    {
        return max(0, (int) config('attendance.max_clock_ahead_minutes', 10));
    }

    public function reminderAt(): string
    {
        return (string) (config('attendance.reminder_at') ?: '17:00');
    }

    public function autoCloseAt(): string
    {
        return (string) (config('attendance.auto_close_at') ?: '23:59');
    }

    public function allowsAttendanceDuringLeave(): bool
    {
        return (bool) config('attendance.allow_attendance_during_leave', false);
    }

    private function dayOf(CarbonInterface|string $value): Carbon
    {
        return Carbon::parse($value)->setTimezone($this->timezone())->startOfDay();
    }
}
