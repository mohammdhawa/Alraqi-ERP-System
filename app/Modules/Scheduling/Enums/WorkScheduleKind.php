<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Enums;

/**
 * How a schedule's positions map to dates (work shifts spec §2).
 *
 * - Weekly — by weekday: position = Carbon's dayOfWeek (0 = Sunday … 6 =
 *   Saturday), so cycle_days is 7.
 * - Cycle — by position in an N-day cycle counted from each person's
 *   cycle_start_date, whatever the weekday (the guards' 3 on, 3 off).
 */
enum WorkScheduleKind: int
{
    case Weekly = 1;
    case Cycle  = 2;

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'أسبوعي',
            self::Cycle  => 'دوري',
        };
    }
}
