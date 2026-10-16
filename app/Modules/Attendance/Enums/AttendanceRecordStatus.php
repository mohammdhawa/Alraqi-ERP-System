<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Enums;

/**
 * The one state STORED on a row, as legacy: a check-in the day closed on with
 * no check-out (written by attendance:auto-close). Every other row's status is
 * null.
 *
 * The day states a report shows — present, absent, on leave — are DERIVED from
 * the rows and are not cases here: storing them would be a second copy of what
 * the rows already say (P1, C25).
 */
enum AttendanceRecordStatus: int
{
    case MissingCheckout = 1;   // انصراف مفقود

    public function label(): string
    {
        return match ($this) {
            self::MissingCheckout => 'انصراف مفقود',
        };
    }
}
