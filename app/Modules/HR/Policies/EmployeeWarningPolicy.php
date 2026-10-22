<?php

declare(strict_types=1);

namespace App\Modules\HR\Policies;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeWarning;
use App\Shared\Support\OutOfScope;
use App\Shared\Support\RequestRowScopes;
use Illuminate\Auth\Access\Response;

/**
 * EmployeeWarningPolicy — a warning is in scope exactly when the person it was
 * issued to is (GAP-3).
 *
 * Amending and deleting are addressed by the warning's own id, not nested under
 * the employee, so the employee has to be found from the warning. It is read
 * WITH trashed rows: a warning outlives the person leaving, and a departed
 * employee's unit still says whose record it is.
 *
 * Denies with `OutOfScope::deny()`, for the reason EmployeePolicy gives.
 */
class EmployeeWarningPolicy
{
    public function update(User $user, EmployeeWarning $warning): Response
    {
        return $this->ensure($user, $warning, 'hr.warnings.update');
    }

    public function delete(User $user, EmployeeWarning $warning): Response
    {
        return $this->ensure($user, $warning, 'hr.warnings.delete');
    }

    private function ensure(User $user, EmployeeWarning $warning, string $permission): Response
    {
        $departmentId = Employee::withTrashed()->whereKey($warning->employee_id)->value('department_id');

        return RequestRowScopes::forUser($user, $permission)->covers($departmentId === null ? null : (int) $departmentId)
            ? Response::allow()
            : OutOfScope::deny('هذا الإنذار يخص موظفًا خارج نطاق صلاحياتك.');
    }
}
