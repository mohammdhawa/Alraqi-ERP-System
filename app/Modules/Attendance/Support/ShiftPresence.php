<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Support;

/**
 * What one shift of a duty holds of the duty's presence (work shifts spec
 * §6.1) — derived on read, never stored. `workHours` is the shift's share:
 * within plus outside, so a duty's shares add up to its real hours.
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
    ) {}

    public static function none(): self
    {
        return new self(false, 0, 0, 0, 0, 0.0);
    }
}
