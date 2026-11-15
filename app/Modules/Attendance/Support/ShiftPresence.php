<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Support;

/**
 * What one shift of a duty holds of the duty's presence (work shifts spec
 * §6.1) — derived on read, never stored. `workHours` is the shift's share:
 * within plus outside, so a duty's shares add up to its real hours.
 * `overtimeMinutes` and `shortMinutes` follow the shift's rules (per-shift
 * rules spec §4.3–§4.4); both are 0 without a check-out.
 */
final readonly class ShiftPresence
{
    public function __construct(
        public bool $present,
        public int $lateMinutes,
        public int $earlyDepartureMinutes,
        public int $minutesWithin,
        public int $minutesOutside,
        public float $workHours,
        public int $overtimeMinutes = 0,
        public int $shortMinutes = 0,
    ) {}

    public static function none(): self
    {
        return new self(false, 0, 0, 0, 0, 0.0);
    }
}
