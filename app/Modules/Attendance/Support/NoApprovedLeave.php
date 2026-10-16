<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Support;

use App\Modules\Attendance\Contracts\ApprovedLeave;

/**
 * The ApprovedLeave binding until the Leave module exists: nobody is on leave.
 * Replaced, not extended, when §4.7 lands — see ApprovedLeave.
 */
final class NoApprovedLeave implements ApprovedLeave
{
    public function covers(int $employeeId, string $date): bool
    {
        return false;
    }
}
