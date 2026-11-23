<?php

declare(strict_types=1);

namespace App\Modules\Documents\Policies;

use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Auth\Models\User;
use App\Modules\Documents\Models\Document;

/**
 * "May this user read, or delete, THIS document?" (documents design §5).
 *
 * Reading: the creator, anyone holding a step on its approval (decided and
 * skipped steps included), or `documents.view`. Deleting: the creator; the
 * super admin passes through `Gate::before`. That the document is still
 * undecided is the delete action's rule, not the policy's.
 */
class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        if ($user->employee_id !== null) {
            $employeeId = (int) $user->employee_id;

            if ($employeeId === (int) $document->creator_id || $this->holdsAStep($employeeId, $document)) {
                return true;
            }
        }

        return $user->hasPermission('documents.view');
    }

    public function delete(User $user, Document $document): bool
    {
        return $user->employee_id !== null && (int) $user->employee_id === (int) $document->creator_id;
    }

    private function holdsAStep(int $employeeId, Document $document): bool
    {
        return ApprovalStep::query()
            ->whereHas('approval', static fn ($approval) => $approval
                ->where('approvable_type', $document->getMorphClass())
                ->where('approvable_id', $document->getKey()))
            ->where('approver_id', $employeeId)
            ->exists();
    }
}
