<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Support;

use App\Modules\Scheduling\Enums\WorkScheduleKind;

/**
 * What a schedule's days must satisfy (work shifts spec §3.1, §9.2) — pure:
 * primitives in, Arabic violations out (C5), called by the model before it
 * writes and by the form request for field errors.
 *
 * THE RUN RULE. A duty is a run of back-to-back shifts — each ending exactly
 * when the next day's begins — and a duty is at most three shifts (72 hours).
 * Positions repeat, so the run is counted around the wrap: a cycle's last
 * position runs into its first, a week's Saturday into its Sunday.
 */
final class ScheduleGuard
{
    public const MAX_RUN = 3;

    public const MAX_CYCLE_DAYS = 28;

    /**
     * @param  array<int, int|null>  $days  position => shift id, or null for a day off
     * @param  array<int, array{starts_at: string, duration_minutes: int, is_active: bool}>  $shiftsById
     * @return list<string>
     */
    public static function violations(WorkScheduleKind $kind, int $cycleDays, array $days, array $shiftsById): array
    {
        if ($kind === WorkScheduleKind::Weekly && $cycleDays !== 7) {
            return ['الجدول الأسبوعي سبعة أيام.'];
        }
        if ($cycleDays < 1 || $cycleDays > self::MAX_CYCLE_DAYS) {
            return ['طول الدورة من يوم واحد إلى 28 يوماً.'];
        }

        $positions = array_map('intval', array_keys($days));
        sort($positions);
        if ($positions !== range(0, $cycleDays - 1)) {
            return ['يجب تحديد كل أيام الجدول.'];
        }

        foreach ($days as $shiftId) {
            if ($shiftId !== null && ! ($shiftsById[$shiftId]['is_active'] ?? false)) {
                return ['الوردية المحددة غير متاحة.'];
            }
        }

        return self::longestRun($cycleDays, $days, $shiftsById) > self::MAX_RUN
            ? ['لا يمكن أن تتصل أكثر من ثلاث ورديات متتالية بلا فاصل.']
            : [];
    }

    /**
     * The longest run of back-to-back shifts, around the wrap; a run that
     * never breaks counts as longer than any limit.
     *
     * @param  array<int, int|null>  $days
     * @param  array<int, array{starts_at: string, duration_minutes: int, is_active: bool}>  $shiftsById
     */
    private static function longestRun(int $cycleDays, array $days, array $shiftsById): int
    {
        $joins = [];
        for ($p = 0; $p < $cycleDays; $p++) {
            $joins[$p] = self::joins($days[$p], $days[($p + 1) % $cycleDays], $shiftsById);
        }

        if (! in_array(false, $joins, true)) {
            return array_filter($days) === [] ? 0 : PHP_INT_MAX;
        }

        $longest = 0;
        for ($p = 0; $p < $cycleDays; $p++) {
            // A run starts at a shift whose predecessor does not join it.
            if ($days[$p] === null || $joins[($p - 1 + $cycleDays) % $cycleDays]) {
                $longest = max($longest, $days[$p] === null ? 0 : 1);

                continue;
            }
            $length = 1;
            for ($q = $p; $joins[$q]; $q = ($q + 1) % $cycleDays) {
                $length++;
            }
            $longest = max($longest, $length);
        }

        return $longest;
    }

    /**
     * Whether the shift on one day ends exactly when the next day's begins.
     *
     * @param  array<int, array{starts_at: string, duration_minutes: int, is_active: bool}>  $shiftsById
     */
    private static function joins(?int $today, ?int $tomorrow, array $shiftsById): bool
    {
        if ($today === null || $tomorrow === null) {
            return false;
        }

        $end = self::minutes($shiftsById[$today]['starts_at']) + $shiftsById[$today]['duration_minutes'];

        return $end === 1440 + self::minutes($shiftsById[$tomorrow]['starts_at']);
    }

    private static function minutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $hour * 60 + $minute;
    }
}
