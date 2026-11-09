<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Support;

use Carbon\Carbon;

/**
 * The attendance clock — the timezone every rule is judged in, "today",
 * parsing a client's moment, and the limits that are not about shifts.
 *
 * Which days are working days, and when a day opens and closes, is each
 * person's schedule now (ExpectedShifts, work shifts spec §7): the company
 * calendar this class used to hold — every day a working day (Q8), check-in
 * open all day (Q9), one close at 23:59 (Q10) — was replaced by the decision
 * round of 2026-11-09.
 *
 * THE FALLBACKS ARE THE SHIPPED VALUES, so a missing key cannot silently
 * change policy.
 */
final class AttendanceCalendar
{
    public function timezone(): string
    {
        return (string) (config('attendance.timezone') ?: config('app.timezone'));
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

    public function minCheckoutGapMinutes(): int
    {
        return max(0, (int) config('attendance.min_checkout_gap_minutes', 60));
    }

    /** Minutes a recorded moment may run ahead of the server (parity audit S-6). */
    public function maxClockAheadMinutes(): int
    {
        return max(0, (int) config('attendance.max_clock_ahead_minutes', 10));
    }

    public function allowsAttendanceDuringLeave(): bool
    {
        return (bool) config('attendance.allow_attendance_during_leave', false);
    }
}
