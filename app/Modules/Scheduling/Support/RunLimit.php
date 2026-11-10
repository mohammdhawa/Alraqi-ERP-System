<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Support;

use App\Modules\Attendance\Support\ExpectedShift;

/**
 * The longest run a person may have (work shifts piece 2, spec §4.2): six
 * back-to-back shifts — two duties of three. Every reader finds a duty's
 * start by looking back six days, which places the cuts correctly for any
 * run of at most six, since a shift is one per date.
 */
final class RunLimit
{
    public const MAX = 6;

    public const MESSAGE = 'يتجاوز هذا التعديل ست ورديات متتالية للموظف.';

    /**
     * The longest run of shifts, each starting exactly when the previous ends.
     *
     * @param  list<ExpectedShift>  $shifts  in date order
     */
    public static function longest(array $shifts): int
    {
        $longest = 0;
        $run = 0;
        $previous = null;

        foreach ($shifts as $shift) {
            $run = $previous !== null && $previous->endsAt->equalTo($shift->startsAt) ? $run + 1 : 1;
            $longest = max($longest, $run);
            $previous = $shift;
        }

        return $longest;
    }
}
