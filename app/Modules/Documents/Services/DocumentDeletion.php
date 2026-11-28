<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Approvals\Services\ApprovalService;
use App\Modules\Auth\Models\User;
use App\Modules\Documents\Enums\DocumentKind;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Exceptions\DocumentDecisionException;
use App\Modules\Documents\Exceptions\DocumentDeletedException;
use App\Modules\Documents\Models\Document;
use Illuminate\Support\Facades\DB;

/**
 * Deleting a document (documents design §7.6): between managers, only before
 * the first signature or rejection, and only by closing its approval through
 * the engine's withdrawal. The engine's callback soft-deletes the document,
 * with the authenticated account (`$by`) as `deleted_by`; the approval and its
 * steps stay, closed, so its parties still resolve it.
 *
 * A document sent to an employee has no approval: it is soft-deleted directly
 * while it awaits the signature (D31), under a lock on its row, because the
 * employee's signing locks the same row; exactly one of the two wins.
 */
class DocumentDeletion
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function delete(Document $document, User $by): void
    {
        if ($document->kind === DocumentKind::ToEmployee) {
            $this->deleteSent($document, $by);

            return;
        }

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

    private function deleteSent(Document $document, User $by): void
    {
        DB::transaction(function () use ($document, $by): void {
            $locked = Document::withTrashed()->lockForUpdate()->findOrFail($document->getKey());

            if ($locked->trashed()) {
                throw new DocumentDeletedException;
            }

            if ($locked->status !== DocumentStatus::AwaitingSignature) {
                throw new DocumentDecisionException('لا يمكن حذف مستند بعد أن أجاب عنه الموظف.');
            }

            $locked->update(['deleted_by' => $by->id]);
            $locked->delete();
        });
    }
}
