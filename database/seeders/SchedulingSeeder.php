<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Scheduling\Enums\WorkScheduleKind;
use App\Modules\Scheduling\Models\Shift;
use App\Modules\Scheduling\Models\WorkSchedule;
use Illuminate\Database\Seeder;

/**
 * The decided schedules (DECISION_ROUND_WORK_SHIFTS.md S3, S7; work shifts
 * spec §3.4), by code and idempotent: a re-run changes nothing.
 *
 * - OFFICE — the default: DAY (09:00, seven hours), Saturday to Thursday,
 *   Friday off. Weekly positions are Carbon's dayOfWeek, 5 = Friday.
 * - GUARD — a six-day cycle: three 24-hour GUARD shifts from 08:00, back to
 *   back (one 72-hour duty), then three days off.
 */
class SchedulingSeeder extends Seeder
{
    public function run(): void
    {
        $day = Shift::withTrashed()->firstOrCreate(
            ['code' => 'DAY'],
            ['name' => 'الدوام الإداري', 'starts_at' => '09:00', 'duration_minutes' => 420, 'is_active' => true],
        );
        $guard = Shift::withTrashed()->firstOrCreate(
            ['code' => 'GUARD'],
            ['name' => 'مناوبة حراسة', 'starts_at' => '08:00', 'duration_minutes' => 1440, 'is_active' => true],
        );

        if (! WorkSchedule::withTrashed()->where('code', 'OFFICE')->exists()) {
            WorkSchedule::createWithDays(
                ['code' => 'OFFICE', 'name' => 'الدوام الإداري: السبت إلى الخميس', 'kind' => WorkScheduleKind::Weekly, 'cycle_days' => 7],
                [0 => $day->id, 1 => $day->id, 2 => $day->id, 3 => $day->id, 4 => $day->id, 5 => null, 6 => $day->id],
            );
        }

        if (! WorkSchedule::withTrashed()->where('code', 'GUARD')->exists()) {
            WorkSchedule::createWithDays(
                ['code' => 'GUARD', 'name' => 'الحراسة: 72 ساعة مناوبة، ثلاثة أيام راحة', 'kind' => WorkScheduleKind::Cycle, 'cycle_days' => 6],
                [0 => $guard->id, 1 => $guard->id, 2 => $guard->id, 3 => null, 4 => null, 5 => null],
            );
        }
    }
}
