<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Approvals\Exceptions\ApprovalException;
use App\Modules\Approvals\Services\ApprovalService;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\NotificationService;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Exceptions\DocumentDecisionException;
use App\Modules\Documents\Models\Document;
use App\Shared\Support\NotificationEventCatalogue;
use Illuminate\Support\Facades\DB;

/**
 * Closing a stuck document as «لم يُستكمل» (documents design §7.8): the
 * system administrator's way out when no reassignment can move it, open to
 * any pending document. One transaction: the document is recorded closed,
 * its approval cancelled through the engine's `cancel()`, and its creator
 * told. The cards, the printed copy and the numbers stay on record; nothing
 * new is signed, so the printed copy is not rebuilt. Its وارد number is free
 * again (`live_import_number`); its صادر is not (D31.3).
 *
 * LOCKS: the approval, then the document — the order a decision takes (the
 * engine locks the approval, then writes the document), so a closing that
 * races a signature waits for it instead of deadlocking with it.
 *
 * WHO is the route's: `can:documents.close`.
 */
final class DocumentClosing
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  string  $reason  validated and trimmed by the request
     *
     * @throws DocumentDecisionException
     * @throws ApprovalException
     */
    public function close(Document $document, User $by, string $reason): Document
    {
        return DB::transaction(function () use ($document, $by, $reason): Document {
            $approval = $document->approval()->lockForUpdate()->first();
            $document = Document::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

            if ($approval === null || $document->status !== DocumentStatus::Pending) {
                throw new DocumentDecisionException('لا يُغلق إلا مستند قيد الاعتماد.');
            }

            // Recorded before the engine is told, so the document's callback
            // knows this cancellation is a closing and not a deletion.
            $document->update([
                'status'        => DocumentStatus::NotCompleted,
                'closed_by'     => $by->getKey(),
                'closed_reason' => $reason,
                'decided_at'    => now(),
            ]);

            $this->approvals->cancel($approval);

            $this->tellCreator($document, $reason);

            return $document;
        });
    }

    private function tellCreator(Document $document, string $reason): void
    {
        $account = User::query()->where('employee_id', $document->creator_id)->value('id');

        if ($account === null) {
            return;
        }

        $this->notifications->sendToUser(
            (int) $account,
            'لم يُستكمل مستندك',
            "أغلق مدير النظام المستند «{$document->title}»: {$reason}. يمكنك إرساله من جديد.",
            type: NotificationEventCatalogue::DOCUMENT_NOT_COMPLETED,
            reference: $document,
        );
    }
}
