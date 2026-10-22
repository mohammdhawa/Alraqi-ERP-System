<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Policies;

use App\Modules\Approvals\Models\Approval;
use App\Modules\Auth\Models\User;
use App\Shared\Support\OutOfScope;
use App\Shared\Support\RequestRowScopes;
use Illuminate\Auth\Access\Response;

/**
 * "May this user see — or hand on — THIS approval?" (design §7, §9 Q4, Q5).
 *
 * VIEW: the requester, anyone holding a step on the chain, or `approvals.view`
 * reaching the requester's CURRENT unit — the same reading attendance gives
 * D6: a transfer takes the person's requests with them. Every action on an
 * approval sits behind this ability, so someone who may not even read it is
 * refused (403) before their body is read, and someone who may read it but
 * whose turn it is not gets the engine's 422 «ليس دورك…». Approving itself is
 * not a permission (mapping §6): the engine decides it from the step.
 *
 * REASSIGN: `approvals.reassign` (the route's `permission:`) reaching the
 * requester's current unit — legacy's admin-only escape hatch, as a scoped
 * grant (§9 Q4 = A).
 *
 * An employee with no unit is reached only by a company-wide grant, the rule
 * EmployeePolicy follows. Wired through the Gate (`#[UsePolicy]` on the
 * model, `can:` on the route), so the super-admin bypass applies.
 */
class ApprovalPolicy
{
    private const OUT_OF_REACH = 'طلب الاعتماد هذا لموظف خارج نطاق صلاحياتك.';

    public function view(User $user, Approval $approval): Response
    {
        if ($this->isParty($user, $approval)) {
            return Response::allow();
        }

        if ($this->reaches($user, $approval, 'approvals.view')) {
            return Response::allow();
        }

        // Someone who reads chains elsewhere is told why this one is not
        // theirs; someone who reads none gets the plain refusal.
        return $user->hasPermission('approvals.view') ? OutOfScope::deny(self::OUT_OF_REACH) : Response::deny();
    }

    public function reassign(User $user, Approval $approval): Response
    {
        return $this->reaches($user, $approval, 'approvals.reassign')
            ? Response::allow()
            : OutOfScope::deny(self::OUT_OF_REACH);
    }

    /**
     * The requester, or a step's current approver — skipped and decided
     * steps included: a person who decided, or was passed over, still reads
     * the outcome.
     */
    private function isParty(User $user, Approval $approval): bool
    {
        if ($user->employee_id === null) {
            return false;
        }

        $employeeId = (int) $user->employee_id;

        return $employeeId === (int) $approval->requested_by
            || $approval->steps()->where('approver_id', $employeeId)->exists();
    }

    private function reaches(User $user, Approval $approval, string $permission): bool
    {
        $unit = $approval->requester?->department_id;

        return RequestRowScopes::forUser($user, $permission)->covers($unit === null ? null : (int) $unit);
    }
}
