<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance\Concerns;

use App\Modules\Scheduling\Enums\WorkScheduleKind;
use App\Modules\Scheduling\Models\Shift;
use App\Modules\Scheduling\Models\WorkSchedule;

/**
 * The default schedule a test needs, rather than OFFICE (work shifts plan
 * Tasks 10 and 13). The shift spans each day — 00:00 for 23 h 59 m, so the
 * days stay separate duties and the shift ends at 23:59 as the old day did.
 *
 * - pinDayOff(...$weekdays): every weekday but those given.
 * - pinEveryDayWorking(): every day — the company calendar the attendance
 *   and leave rules were written against before shifts (Q8), so a test of
 *   those rules keeps testing them and not the schedule.
 */
trait PinsADayOff
{
    /** @param  int  ...$weekdays  Carbon dayOfWeek, 0 = Sunday */
    protected function pinDayOff(int ...$weekdays): void
    {
        $shift = Shift::query()->firstOrCreate(
            ['code' => 'ALLDAY'],
            ['name' => 'اليوم كاملاً', 'starts_at' => '00:00', 'duration_minutes' => 1439],
        );
        $days = array_fill(0, 7, $shift->id);
        foreach ($weekdays as $weekday) {
            $days[$weekday] = null;
        }

        $code = $weekdays === [] ? 'EVERYDAY' : 'DAYOFF'.implode('', $weekdays);
        if (! WorkSchedule::query()->where('code', $code)->exists()) {
            WorkSchedule::createWithDays(['code' => $code, 'name' => 'جدول اختبار', 'kind' => WorkScheduleKind::Weekly, 'cycle_days' => 7], $days);
        }
        config(['attendance.default_schedule' => $code]);
    }

    protected function pinEveryDayWorking(): void
    {
        $this->pinDayOff();
    }
}
