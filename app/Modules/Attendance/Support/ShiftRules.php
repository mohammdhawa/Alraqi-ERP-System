<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Support;

/**
 * A shift's attendance rules on one date (per-shift rules spec §2.1, §3.1):
 * the two graces, how early check-in opens, how long after the end a check-out
 * is accepted, overtime, and the minutes a completed day needs.
 *
 * Carried on every ExpectedShift, so each reader judges a day by the rules in
 * force on it. config/attendance.php supplies only the defaults (§2.2).
 */
final readonly class ShiftRules
{
    /** The effective date of a shift's first version: before any day it can affect. */
    public const FIRST_DAY = '2000-01-01';

    public function __construct(
        public int $lateGraceMinutes,
        public int $earlyLeaveGraceMinutes,
        public int $checkInOpensMinutes,
        public int $checkOutClosesMinutes,
        public bool $overtimeEnabled,
        public int $overtimeMinMinutes,
        public int $requiredMinutes,
    ) {}

    /** The company defaults for a shift of the given length: overtime off, the whole length required. */
    public static function defaults(int $durationMinutes): self
    {
        $grace = max(0, (int) config('attendance.grace_minutes', 15));

        return new self(
            lateGraceMinutes: $grace,
            earlyLeaveGraceMinutes: $grace,
            checkInOpensMinutes: max(0, (int) config('attendance.check_in_opens_minutes', 60)),
            checkOutClosesMinutes: max(0, (int) config('attendance.close_after_minutes', 240)),
            overtimeEnabled: false,
            overtimeMinMinutes: 0,
            requiredMinutes: max(1, $durationMinutes),
        );
    }

    /** @return array<string, int|bool> the column names of shift_rules */
    public function toArray(): array
    {
        return [
            'late_grace_minutes'        => $this->lateGraceMinutes,
            'early_leave_grace_minutes' => $this->earlyLeaveGraceMinutes,
            'check_in_opens_minutes'    => $this->checkInOpensMinutes,
            'check_out_closes_minutes'  => $this->checkOutClosesMinutes,
            'overtime_enabled'          => $this->overtimeEnabled,
            'overtime_min_minutes'      => $this->overtimeMinMinutes,
            'required_minutes'          => $this->requiredMinutes,
        ];
    }
}
