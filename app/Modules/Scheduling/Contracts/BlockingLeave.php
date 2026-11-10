<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Contracts;

/**
 * What Scheduling needs to know of leave (work shifts piece 2, spec §6.2
 * rule 6), answered by the Leave module — as Attendance reads approved leave
 * through ApprovedLeave, so Scheduling never imports Leave's models.
 */
interface BlockingLeave
{
    /** Whether the employee has pending or approved leave on any day of [$from, $to] (Y-m-d, inclusive). */
    public function blocksAny(int $employeeId, string $from, string $to): bool;
}
