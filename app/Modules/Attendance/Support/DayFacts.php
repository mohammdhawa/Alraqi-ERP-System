<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Support;

use App\Modules\Attendance\Contracts\LeaveSpan;
use App\Modules\Attendance\Enums\AttendanceDayState;
use App\Modules\Attendance\Models\AttendanceRecord;

/**
 * One person's date, as every report reads it (work shifts spec §6) — built
 * by AttendanceDays, never stored.
 *
 * `in`/`out` are the duty's check-in and check-out when this date's shift
 * holds some of the duty's presence (or the duty is a missing check-out,
 * which marks every shift); `hours` is this date's share. `counted` says the
 * date is in the days so far (parity audit S-1): on or before today and not
 * "not yet".
 */
final readonly class DayFacts
{
    public function __construct(
        public string $date,
        public AttendanceDayState $state,
        public ?ExpectedShift $shift,
        public ?AttendanceRecord $in,
        public ?AttendanceRecord $out,
        public ?ShiftPresence $presence,
        public ?float $hours,
        public ?LeaveSpan $span,
        public ?AttendanceRecord $refusedIn,
        public int $refusedCount,
        public bool $counted,
    ) {}

    public function working(): bool
    {
        return $this->shift !== null;
    }

    public function outsideSchedule(): bool
    {
        return (bool) $this->in?->outside_schedule;
    }

    public function lateMinutes(): int
    {
        return $this->presence?->lateMinutes ?? 0;
    }

    public function earlyDepartureMinutes(): int
    {
        return $this->presence?->earlyDepartureMinutes ?? 0;
    }

    /** Overtime counts only on a completed day (per-shift rules spec §4.3). */
    public function overtimeMinutes(): int
    {
        return $this->state === AttendanceDayState::CheckedOut ? ($this->presence?->overtimeMinutes ?? 0) : 0;
    }

    /**
     * A completed day only: never a missing check-out, leave, an excuse or an
     * absence (spec §4.4) — also when presence outranks a leave or excuse span
     * on the day (attendance during leave, or work on an excused day).
     */
    public function shortMinutes(): int
    {
        return $this->state === AttendanceDayState::CheckedOut && $this->span === null
            ? ($this->presence?->shortMinutes ?? 0)
            : 0;
    }

    public function minutesWithin(): int
    {
        return $this->presence?->minutesWithin ?? 0;
    }

    public function minutesOutside(): int
    {
        return $this->presence?->minutesOutside ?? 0;
    }
}
