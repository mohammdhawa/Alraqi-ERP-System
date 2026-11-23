<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Contracts;

use App\Modules\Auth\Models\User;

/**
 * What an approvable subject implements when its chain is not for HR's
 * all-approvals screens (documents design §7.4): `approvals.view` alone does
 * not open its approval, nor list it. Its parties always read it; this names
 * who else may.
 */
interface RestrictsApprovalVisibility
{
    /**
     * Whether this user may read the subject's approvals without being the
     * requester or holding a step.
     */
    public static function mayReadApprovalsWithoutBeingParty(User $user): bool;
}
