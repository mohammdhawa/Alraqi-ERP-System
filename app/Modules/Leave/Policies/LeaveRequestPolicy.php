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
