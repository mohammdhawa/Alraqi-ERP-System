<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Contracts;

/**
 * What attendance needs to know about leave.
 *
 * WHY AN INTERFACE ATTENDANCE OWNS. Attendance is built before leave (work
 * order §8, dependency order), and leave will need attendance's calendar to
 * count working days. If attendance imported the Leave module, the two would
 * depend on each other. So attendance states what it needs here, binds
 * NoApprovedLeave until leave exists, and the Leave module binds its own
 * implementation — the dependency runs Leave → Attendance only.
 *
 * "Approved" means a request whose approval completed, or an excuse HR filed
 * (approved at once, Q5). A cancelled one is neither.
 */
interface ApprovedLeave
{
    /** True when the employee has approved leave covering $date (Y-m-d, attendance timezone). */
    public function covers(int $employeeId, string $date): bool;

    /**
     * The approved leave of these employees that overlaps [$from, $to]
     * (Y-m-d, inclusive) — unclipped; the reports clip to their own window.
     *
     * @param  array<int, int>  $employeeIds
     * @return array<int, LeaveSpan>
     */
    public function spans(array $employeeIds, string $from, string $to): array;
}
