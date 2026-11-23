<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Approvals\Services\ApprovalService;
use App\Modules\Auth\Models\User;
use App\Modules\Documents\Exceptions\DocumentDecisionException;
use App\Modules\Documents\Models\Document;

/**
 * Deleting a document (documents design §7.6): only before the first
 * signature or rejection, and only by closing its approval through the
 * engine's withdrawal. The engine's callback soft-deletes the document, with
 * the authenticated account (`$by`) as `deleted_by`; the approval and its
 * steps stay, closed, so its parties still resolve it.
 */
class DocumentDeletion
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function delete(Document $document, User $by): void
    {
        $approval = $document->approval()->with('steps')->first();

        if ($approval === null || $approval->steps->contains(static fn (ApprovalStep $step): bool => $step->status->isDecision())) {
            throw new DocumentDecisionException('لا يمكن حذف مستند بعد أول توقيع عليه.');
        }

        // The engine lets only the requester withdraw: the creator's account,
        // even when the administrator is the one deleting.
        $creator = User::query()->where('employee_id', $document->creator_id)->first();

        if ($creator === null) {
            throw new DocumentDecisionException('تعذّر حذف المستند: لا يوجد حساب لمنشئه.');
        }

        $this->approvals->withdraw($approval, $creator);
    }
}
