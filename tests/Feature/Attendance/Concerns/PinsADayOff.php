<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance\Concerns;

use App\Modules\Scheduling\Enums\WorkScheduleKind;
use App\Modules\Scheduling\Models\Shift;
use App\Modules\Scheduling\Models\WorkSchedule;

/**
 * Weekdays off for everyone, through the schedule rather than the retired
 * `attendance.working_days` setting (work shifts plan Task 10): a default
 * schedule whose shift spans each day (00:00, 23 h 59 m — so the days stay
 * separate duties) on every weekday but those given.
 */
trait PinsADayOff
{
    /** @param  int  ...$weekdays  Carbon dayOfWeek, 0 = Sunday */
    protected function pinDayOff(int ...$weekdays): void
    {
        $shift = Shift::create(['code' => 'ALLDAY', 'name' => 'اليوم كاملاً', 'starts_at' => '00:00', 'duration_minutes' => 1439]);
        $days = array_fill(0, 7, $shift->id);
        foreach ($weekdays as $weekday) {
            $days[$weekday] = null;
        }

        WorkSchedule::createWithDays(['code' => 'DAYOFF', 'name' => 'يوم عطلة أسبوعي', 'kind' => WorkScheduleKind::Weekly, 'cycle_days' => 7], $days);
        config(['attendance.default_schedule' => 'DAYOFF']);
    }
}
