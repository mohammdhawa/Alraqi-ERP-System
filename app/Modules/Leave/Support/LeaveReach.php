<?php

declare(strict_types=1);

namespace App\Modules\Leave\Support;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Shared\Support\OutOfScope;
use App\Shared\Support\RequestRowScopes;

/**
 * "Does this caller's grant for $permission reach this employee?" — the one
 * check every HR leave action makes about a PERSON (a balance, an excuse),
 * through the employee's CURRENT unit (D6), as attendance.
 *
 * An employee with no unit is reached only by a company-wide grant
 * (RowScope). Out of reach is the 403 `out_of_scope` decision 19 asks for.
 */
final class LeaveReach
{
    public static function ensure(User $user, Employee $employee, string $permission): void
    {
        $unit = $employee->department_id;

        if (! RequestRowScopes::forUser($user, $permission)->covers($unit === null ? null : (int) $unit)) {
            OutOfScope::deny('هذا الموظف خارج نطاق صلاحياتك.')->authorize();
        }
    }
}
