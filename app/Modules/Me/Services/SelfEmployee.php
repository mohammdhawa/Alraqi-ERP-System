<?php

declare(strict_types=1);

namespace App\Modules\Me\Services;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Me\Exceptions\NoLinkedEmployeeException;

/**
 * The person a `/api/me` call is about: always the caller's own employee,
 * resolved from the account — never from an id in the request (C7).
 */
final class SelfEmployee
{
    /**
     * @throws NoLinkedEmployeeException the account has no employee
     */
    public function of(User $user): Employee
    {
        $employee = $user->employee_id === null ? null : Employee::find($user->employee_id);

        if (! $employee instanceof Employee) {
            throw new NoLinkedEmployeeException;
        }

        return $employee;
    }
}
