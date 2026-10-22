<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Exceptions\AttendanceException;
use App\Modules\Attendance\Exceptions\NotAnAttendeeException;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;

/**
 * Whose attendance a self-service call is about: always the caller's own
 * employee, resolved from the account — never from an id in the request (C7).
 *
 * WHO RECORDS (Q7, 2026-10-16): every active employee with an account. No
 * eligibility flag and no permission. Reading one's own history needs only
 * the link: a suspended employee still sees what they recorded.
 */
final class AttendeeResolver
{
    /**
     * @throws NotAnAttendeeException the account has no employee
     */
    public function employeeOf(User $user): Employee
    {
        $employee = $user->employee_id === null ? null : Employee::find($user->employee_id);

        if (! $employee instanceof Employee) {
            throw new NotAnAttendeeException;
        }

        return $employee;
    }

    /**
     * @throws NotAnAttendeeException the account has no employee
     * @throws AttendanceException the employee is not active (suspended)
     */
    public function recordingEmployeeOf(User $user): Employee
    {
        $employee = $this->employeeOf($user);

        if ($employee->status !== EmployeeStatus::Active) {
            throw new AttendanceException('لا يمكن تسجيل الحضور لموظف ليس على رأس عمله.');
        }

        return $employee;
    }
}
