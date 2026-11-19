<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Support;

use Carbon\CarbonInterface;

/**
 * A duty's presence — its check-in to its check-out — spread over its shifts
 * (work shifts spec §6.1). Pure: no records, no clock but the one passed in.
 *
 * - present: the presence overlaps the shift; minutesWithin is the overlap.
 * - late: on the first shift with presence, when the presence starts more
 *   than the grace after that shift's start — counted from the start, so
 *   09:16 is 16 minutes late.
 * - early departure: the same against the end of the last shift with
 *   presence; none while the check-in is still open.
 * - outside: time before the duty's start, on the first shift with presence,
 *   and after its end, on the last shift.
 *
 * An open check-in's presence runs to $now. Whole minutes throughout: each
 * moment counts as the minute it reads (22:00:32 is 22:00), so a shift worked
 * from the minute it starts to the minute it ends is not a minute short for
 * the seconds of the tap.
 */
final class DutyPresence
{
    /** @return array<string, ShiftPresence> keyed by shift date */
    public static function split(Duty $duty, ?CarbonInterface $in, ?CarbonInterface $out, CarbonInterface $now): array
    {
        if ($in === null) {
            return self::nothing($duty);
        }

        // Copies: a record's own (mutable) moment keeps its seconds.
        $in = $in->copy()->startOfMinute();
        $out = $out?->copy()->startOfMinute();
        $end = $out ?? $now->copy()->startOfMinute();
        $overlaps = array_map(
            static fn (ExpectedShift $shift): int => self::minutes(max($in, $shift->startsAt), min($end, $shift->endsAt)),
            $duty->shifts,
        );

        $presentAt = array_keys(array_filter($overlaps, static fn (int $m): bool => $m > 0));
        $first = $presentAt[0] ?? null;
        $last = $presentAt === [] ? null : $presentAt[array_key_last($presentAt)];

        $before = self::minutes($in, min($end, $duty->startsAt()));
        $after = self::minutes(max($in, $duty->endsAt()), $end);

        $result = [];
        foreach ($duty->shifts as $index => $shift) {
            $late = 0;
            $early = 0;
            $outside = 0;

            if ($index === $first) {
                $late = self::beyondGrace(self::minutes($shift->startsAt, max($in, $shift->startsAt)), $shift->rules()->lateGraceMinutes);
                $outside += $before;
            }
            if ($index === $last && $out !== null) {
                $early = self::beyondGrace(self::minutes(min($end, $shift->endsAt), $shift->endsAt), $shift->rules()->earlyLeaveGraceMinutes);
            }
            $overtime = 0;
            if ($index === array_key_last($duty->shifts)) {
                $outside += $after;
                $overtime = $out === null ? 0 : self::overtime(self::minutes($duty->endsAt(), $out), $shift->rules());
            }

            $result[$shift->date] = new ShiftPresence(
                present: $overlaps[$index] > 0,
                lateMinutes: $late,
                earlyDepartureMinutes: $early,
                minutesWithin: $overlaps[$index],
                minutesOutside: $outside,
                workHours: round(($overlaps[$index] + $outside) / 60, 2),
                overtimeMinutes: $overtime,
                // Within the shift only: overtime and early arrival do not make up for it (spec §4.4).
                shortMinutes: $out === null ? 0 : max(0, $shift->rules()->requiredMinutes - $overlaps[$index]),
            );
        }

        return $result;
    }

    /** @return array<string, ShiftPresence> */
    private static function nothing(Duty $duty): array
    {
        $none = [];
        foreach ($duty->shifts as $shift) {
            $none[$shift->date] = ShiftPresence::none();
        }

        return $none;
    }

    /** Whole minutes from $from to $to; 0 when $to is not after $from. */
    private static function minutes(CarbonInterface $from, CarbonInterface $to): int
    {
        return $to->gt($from) ? intdiv($to->getTimestamp() - $from->getTimestamp(), 60) : 0;
    }

    /** All the minutes after the end, once they reach the minimum, when the shift counts overtime (spec §4.3). */
    private static function overtime(int $afterEnd, ShiftRules $rules): int
    {
        return $rules->overtimeEnabled && $afterEnd > 0 && $afterEnd >= $rules->overtimeMinMinutes ? $afterEnd : 0;
    }

    private static function beyondGrace(int $minutes, int $grace): int
    {
        return $minutes > $grace ? $minutes : 0;
    }
}
