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
 * An open check-in's presence runs to $now. The open day (spec §4.5) has no
 * late, early or outside minutes. Whole minutes throughout.
 */
final class DutyPresence
{
    /** @return array<string, ShiftPresence> keyed by shift date */
    public static function split(Duty $duty, ?CarbonInterface $in, ?CarbonInterface $out, CarbonInterface $now, int $graceMinutes): array
    {
        if ($in === null) {
            return self::nothing($duty);
        }

        $end = $out ?? $now;
        $overlaps = array_map(
            static fn (ExpectedShift $shift): int => self::minutes(max($in, $shift->startsAt), min($end, $shift->endsAt)),
            $duty->shifts,
        );

        $presentAt = array_keys(array_filter($overlaps, static fn (int $m): bool => $m > 0));
        $first = $presentAt[0] ?? null;
        $last = $presentAt === [] ? null : $presentAt[array_key_last($presentAt)];

        $before = $duty->isOpen ? 0 : self::minutes($in, min($end, $duty->startsAt()));
        $after = $duty->isOpen ? 0 : self::minutes(max($in, $duty->endsAt()), $end);

        $result = [];
        foreach ($duty->shifts as $index => $shift) {
            $late = 0;
            $early = 0;
            $outside = 0;

            if (! $duty->isOpen && $index === $first) {
                $late = self::beyondGrace(self::minutes($shift->startsAt, max($in, $shift->startsAt)), $graceMinutes);
                $outside += $before;
            }
            if (! $duty->isOpen && $index === $last && $out !== null) {
                $early = self::beyondGrace(self::minutes(min($end, $shift->endsAt), $shift->endsAt), $graceMinutes);
            }
            if ($index === array_key_last($duty->shifts)) {
                $outside += $after;
            }

            $result[$shift->date] = new ShiftPresence(
                present: $overlaps[$index] > 0,
                lateMinutes: $late,
                earlyDepartureMinutes: $early,
                minutesWithin: $overlaps[$index],
                minutesOutside: $outside,
                workHours: round(($overlaps[$index] + $outside) / 60, 2),
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

    private static function beyondGrace(int $minutes, int $grace): int
    {
        return $minutes > $grace ? $minutes : 0;
    }
}
