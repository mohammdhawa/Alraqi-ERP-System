<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * UnassignRoleRequest — the same payload as AssignRoleRequest, read for
 * revoking.
 *
 * ⚙️ A SOFT-DELETED UNIT IS ACCEPTED HERE. A unit grant keeps working after
 * its unit is soft-deleted — `UserRole::department()` and
 * `Department::subtreeIds()` both walk trashed units — so it must stay
 * revocable on its own. Requiring a live unit, as a new grant does, left
 * "revoke every grant of the role" as the only way to remove it.
 */
class UnassignRoleRequest extends AssignRoleRequest
{
    protected function departmentExists(): Exists
    {
        return Rule::exists('departments', 'id');
    }

    protected function refusesNarrowedSuperAdmin(): bool
    {
        return false;
    }

    protected function refusesInertGrant(): bool
    {
        return false;
    }
}
