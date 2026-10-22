<?php

declare(strict_types=1);

namespace App\Modules\Leave\Policies;

use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Auth\Models\User;
use App\Modules\Leave\Models\LeaveRequest;
use App\Shared\Support\OutOfScope;
use App\Shared\Support\RequestRowScopes;
use Illuminate\Auth\Access\Response;

/**
 * "May this user read THIS leave — and its files?" (LEAVE_BUILD.md §4).
 *
 * The employee it is about; anyone holding a step on its approval (decided
 * and skipped steps included — the approvals engine's reading); or
 * `leave.requests.view` reaching the employee's CURRENT unit (D6). Someone
 * who holds that permission for other units is told why; someone who holds
 * nothing gets the plain refusal.
 *
 * Wired through the Gate (`#[UsePolicy]` on the model, `can:` on the route),
 * so the super-admin bypass applies.
 */
class LeaveRequestPolicy
{
    public function view(User $user, LeaveRequest $leave): Response
    {
        if ($user->employee_id !== null && $this->isParty((int) $user->employee_id, $leave)) {
            return Response::allow();
        }

        $unit = $leave->employee?->department_id;

        if (RequestRowScopes::forUser($user, 'leave.requests.view')->covers($unit === null ? null : (int) $unit)) {
            return Response::allow();
        }

        return $user->hasPermission('leave.requests.view')
            ? OutOfScope::deny('طلب الإجازة هذا لموظف خارج نطاق صلاحياتك.')
            : Response::deny();
    }

    /**
     * Undoing an HR excuse: `leave.excuses.delete` (the route's
     * `permission:`) reaching the employee's current unit — the same reach
     * the excuse was filed under, so nobody retracts an entry for someone
     * they could not have excused (legacy).
     */
    public function cancelExcuse(User $user, LeaveRequest $leave): Response
    {
        return $this->reaches($user, $leave, 'leave.excuses.delete');
    }

    /**
     * Recording a return to work: `leave.requests.returnToWork` reaching the
     * employee's current unit.
     */
    public function returnToWork(User $user, LeaveRequest $leave): Response
    {
        return $this->reaches($user, $leave, 'leave.requests.returnToWork');
    }

    private function reaches(User $user, LeaveRequest $leave, string $permission): Response
    {
        $unit = $leave->employee?->department_id;

        return RequestRowScopes::forUser($user, $permission)->covers($unit === null ? null : (int) $unit)
            ? Response::allow()
            : OutOfScope::deny('هذا الموظف خارج نطاق صلاحياتك.');
    }

    private function isParty(int $employeeId, LeaveRequest $leave): bool
    {
        if ($employeeId === (int) $leave->employee_id) {
            return true;
        }

        return ApprovalStep::query()
            ->whereHas('approval', static fn ($approval) => $approval
                ->where('approvable_type', $leave->getMorphClass())
                ->where('approvable_id', $leave->getKey()))
            ->where('approver_id', $employeeId)
            ->exists();
    }
}
