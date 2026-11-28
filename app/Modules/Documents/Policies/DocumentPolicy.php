<?php

declare(strict_types=1);

namespace App\Modules\Documents\Policies;

use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Auth\Models\User;
use App\Modules\Documents\Models\Document;

/**
 * "May this user read, comment on, answer or delete THIS document?"
 * (documents design §5).
 *
 * Reading: the creator (a sent document's sender), the recipient of a sent
 * document, anyone holding a step on a document's approval (decided and
 * skipped steps included), or `documents.view`. Commenting: whoever may read
 * it, except its recipient (§5, call 5). Answering: the recipient of a sent
 * document; that it still awaits the signature is the service's rule, so a
 * late tap gets its words rather than a bare 403. Deleting: the creator; the
 * super admin passes through `Gate::before`. That the document is still
 * undecided (or unanswered) is the delete action's rule, not the policy's.
 */
class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        if ($user->employee_id !== null) {
            $employeeId = (int) $user->employee_id;

            if ($employeeId === (int) $document->creator_id
                || $document->isRecipient($user)
                || $this->holdsAStep($employeeId, $document)) {
                return true;
            }
        }

        return $user->hasPermission('documents.view');
    }

    public function comment(User $user, Document $document): bool
    {
        return ! $document->isRecipient($user) && $this->view($user, $document);
    }

    public function answer(User $user, Document $document): bool
    {
        return $document->isRecipient($user);
    }

    public function delete(User $user, Document $document): bool
    {
        return $user->employee_id !== null && (int) $user->employee_id === (int) $document->creator_id;
    }

    private function holdsAStep(int $employeeId, Document $document): bool
    {
        // The lists load the approval and its steps (`DocumentResource::RELATIONS`):
        // answer from them, so a page of rows costs no query per row.
        if ($document->relationLoaded('approval') && ($document->approval === null || $document->approval->relationLoaded('steps'))) {
            return $document->approval?->steps->contains(
                static fn (ApprovalStep $step): bool => (int) $step->approver_id === $employeeId,
            ) ?? false;
        }

        return ApprovalStep::query()
            ->whereHas('approval', static fn ($approval) => $approval
                ->where('approvable_type', $document->getMorphClass())
                ->where('approvable_id', $document->getKey()))
            ->where('approver_id', $employeeId)
            ->exists();
    }
}
