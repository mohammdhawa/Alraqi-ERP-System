<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Contracts;

/**
 * What attendance needs to know about leave: is this person on approved leave
 * on this day?
 *
 * WHY AN INTERFACE ATTENDANCE OWNS. Attendance is built before leave (work
 * order §8, dependency order), and leave will need attendance's calendar to
 * count working days. If attendance imported the Leave module, the two would
 * depend on each other. So attendance states what it needs here, binds
 * NoApprovedLeave until leave exists, and the Leave module binds its own
 * implementation — the dependency runs Leave → Attendance only.
 */
interface ApprovedLeave
{
    /** True when the employee has approved leave covering $date (Y-m-d, attendance timezone). */
    public function covers(int $employeeId, string $date): bool;
}
