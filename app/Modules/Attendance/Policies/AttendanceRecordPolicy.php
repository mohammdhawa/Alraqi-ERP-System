<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Policies;

use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Auth\Models\User;
use App\Shared\Support\OutOfScope;
use App\Shared\Support\RequestRowScopes;
use Illuminate\Auth\Access\Response;

/**
 * "May this user touch THIS attendance row?" (GAP-3; work order §4.5 slice 2).
 *
 * The row belongs to the unit its employee is in NOW (D6, 2026-10-16) — there
 * is no unit snapshot on the row, so after a transfer the new unit's reach
 * covers the person's whole history and the old unit's covers none of it.
 * An employee with no unit is reached only by a company-wide grant, the rule
 * EmployeePolicy follows.
 *
 * Each ability names its own permission, so the scope consulted is always
 * the verb's: a manager who may view their units' attendance does not, by
 * that, reach the rows they could refuse.
 *
 * Wired through the Gate (`#[UsePolicy]` on the model, `can:` on the route),
 * so the super-admin bypass applies without this class knowing about it.
 */
class AttendanceRecordPolicy
{
    public function view(User $user, AttendanceRecord $record): Response
    {
        return $this->ensure($user, $record, 'attendance.records.view');
    }

    public function correct(User $user, AttendanceRecord $record): Response
    {
        return $this->ensure($user, $record, 'attendance.records.correct');
    }

    public function reject(User $user, AttendanceRecord $record): Response
    {
        return $this->ensure($user, $record, 'attendance.records.reject');
    }

    private function ensure(User $user, AttendanceRecord $record, string $permission): Response
    {
        $unit = $record->employee?->department_id;

        return RequestRowScopes::forUser($user, $permission)->covers($unit === null ? null : (int) $unit)
            ? Response::allow()
            : OutOfScope::deny('سجل الحضور هذا لموظف خارج نطاق صلاحياتك.');
    }
}
