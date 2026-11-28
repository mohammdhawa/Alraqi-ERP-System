<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\NotificationService;
use App\Modules\Documents\Enums\Confirmation;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Enums\MarkKind;
use App\Modules\Documents\Exceptions\DocumentDecisionException;
use App\Modules\Documents\Exceptions\SignatureRequiredException;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentMark;
use App\Modules\Documents\Models\EmployeeSignature;
use App\Modules\Documents\Pdf\DocumentPrinter;
use App\Modules\Documents\Pdf\PdfWorkspace;
use App\Modules\HR\Models\Employee;
use App\Shared\Support\NotificationEventCatalogue;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The employee's answer to a document sent to them (documents design §9.2,
 * §10.3): «قرأت ووافقت», their drawn signature printed on it, or a refusal
 * with a reason. Both are confirmed and final (I.13); each files the document
 * on the employee's record (§9.4) and tells the sender.
 *
 * The checks run before anything is written, in the order that makes each
 * refusal make sense: the caller is the recipient, the document awaits the
 * answer, the caller has drawn a signature (signing only), the password.
 * Then one transaction, whose first act is to lock the document's row and
 * read its status again: the deletion locks the same row the same way
 * (`DocumentDeletion`), so of a signature and a deletion, or of two taps on
 * two phones, exactly one wins, and the other is told the document no longer
 * awaits the answer. A signature then adds the block, prints a new copy and
 * records it. Each answer files the document and tells the sender in that
 * transaction, as piece 2 tells: the inbox row commits with the answer, and
 * only the push waits for the commit. A throw inside it rolls everything back
 * and deletes a signature's new copy. A failure after the commit (an
 * after-commit callback) deletes nothing; a failed commit itself can at worst
 * leave an unreferenced printed copy, never a missing committed one.
 *
 * WHO: the route's `can:answer,document` admits the recipient alone — and the
 * system administrator, whom the gate's bypass passes. The recipient is
 * checked again here, so the bypass answers nothing.
 */
final class DocumentAnswers
{
    private const NOT_AWAITING = 'هذا المستند ليس بانتظار توقيعك.';

    public function __construct(
        private readonly Signatures $signatures,
        private readonly DocumentPrinter $printer,
        private readonly RecordFiling $filing,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @throws AuthorizationException not the recipient (403)
     * @throws DocumentDecisionException no longer awaiting the answer, or a wrong password (422)
     * @throws SignatureRequiredException no signature drawn yet (428)
     */
    public function sign(Document $document, User $by, Confirmation $confirmedBy, ?string $password): Document
    {
        $recipient = $this->recipientOf($document, $by);

        $this->refuseUnlessAwaiting($document);

        $signature = $this->signatures->current($recipient) ?? throw new SignatureRequiredException;

        $this->checkPassword($by, $confirmedBy, $password);

        return DB::transaction(function () use ($document, $recipient, $signature, $confirmedBy): Document {
            $locked = $this->lockAwaiting($document);
            $now = now();

            $this->addSignature($locked, $recipient, $signature, $confirmedBy, $now);

            // The printer writes nothing when it throws.
            $printed = $this->printer->print($locked);

            // Inside the transaction, not around it, as piece 2's decision: a
            // throw from an after-commit callback must not delete the copy the
            // committed signature names.
            try {
                $locked->update([
                    'status'       => DocumentStatus::Signed,
                    'signed_at'    => $now,
                    'printed_path' => $printed,
                ]);

                $this->filing->file($locked);

                $this->tellSenderOfSignature($locked, $recipient);
            } catch (Throwable $e) {
                Storage::disk(PdfWorkspace::DISK)->delete($printed);

                throw $e;
            }

            return $locked;
        });
    }

    /**
     * @param  string  $reason  validated and trimmed by the request
     *
     * @throws AuthorizationException not the recipient (403)
     * @throws DocumentDecisionException no longer awaiting the answer, or a wrong password (422)
     */
    public function refuse(Document $document, User $by, string $reason, Confirmation $confirmedBy, ?string $password): Document
    {
        $recipient = $this->recipientOf($document, $by);

        $this->refuseUnlessAwaiting($document);

        $this->checkPassword($by, $confirmedBy, $password);

        // Nothing is printed: the copy as sent is the one filed.
        return DB::transaction(function () use ($document, $recipient, $reason): Document {
            $locked = $this->lockAwaiting($document);

            $locked->update([
                'status'         => DocumentStatus::Refused,
                'refused_at'     => now(),
                'refusal_reason' => $reason,
            ]);

            $this->filing->file($locked);

            $this->tellSenderOfRefusal($locked, $recipient, $reason);

            return $locked;
        });
    }

    /**
     * The employee the document is addressed to, when the caller is them.
     *
     * @throws AuthorizationException
     */
    private function recipientOf(Document $document, User $by): Employee
    {
        if (! $document->isRecipient($by)) {
            throw new AuthorizationException('ليس لديك صلاحية لتنفيذ هذا الإجراء.');
        }

        return $document->recipient()->firstOrFail();
    }

    /**
     * @throws DocumentDecisionException
     */
    private function refuseUnlessAwaiting(Document $document): void
    {
        if ($document->trashed() || $document->status !== DocumentStatus::AwaitingSignature) {
            throw new DocumentDecisionException(self::NOT_AWAITING);
        }
    }

    /**
     * The document's row, locked as the deletion locks it, and still awaiting
     * the answer: a model read before another answer or a deletion committed
     * (a second tap, a second phone) is refused here.
     *
     * @throws DocumentDecisionException
     */
    private function lockAwaiting(Document $document): Document
    {
        $locked = Document::withTrashed()->lockForUpdate()->findOrFail($document->getKey());

        $this->refuseUnlessAwaiting($locked);

        return $locked;
    }

    /**
     * @throws DocumentDecisionException
     */
    private function checkPassword(User $by, Confirmation $confirmedBy, ?string $password): void
    {
        if ($confirmedBy === Confirmation::Password && ! Hash::check((string) $password, (string) $by->password)) {
            throw new DocumentDecisionException('كلمة المرور غير صحيحة.');
        }
    }

    /**
     * The one signature block (the plan's call 3): no step, unit or stamp, the
     * signature version drawn now.
     */
    private function addSignature(Document $document, Employee $recipient, EmployeeSignature $signature, Confirmation $confirmedBy, Carbon $at): DocumentMark
    {
        return DocumentMark::create([
            'document_id'   => $document->getKey(),
            'position'      => 1,
            'kind'          => MarkKind::EmployeeSignature,
            'employee_id'   => $recipient->getKey(),
            'employee_name' => $recipient->full_name,
            'signature_id'  => $signature->getKey(),
            'decisive'      => false,
            'confirmed_by'  => $confirmedBy,
            'marked_at'     => $at,
        ]);
    }

    /** To the sending account (`created_by`), inside the answer's transaction. */
    private function tellSenderOfSignature(Document $document, Employee $recipient): void
    {
        if ($document->created_by === null) {
            return;
        }

        $this->notifications->sendToUser(
            (int) $document->created_by,
            "وقّع {$recipient->full_name} على المستند",
            "وقّع {$recipient->full_name} على المستند «{$document->title}».",
            type: NotificationEventCatalogue::DOCUMENT_SIGNED,
            reference: $document,
        );
    }

    /** To the sending account (`created_by`), with the reason, inside the answer's transaction. */
    private function tellSenderOfRefusal(Document $document, Employee $recipient, string $reason): void
    {
        if ($document->created_by === null) {
            return;
        }

        $this->notifications->sendToUser(
            (int) $document->created_by,
            "رفض {$recipient->full_name} المستند",
            "رفض {$recipient->full_name} المستند «{$document->title}»: {$reason}",
            type: NotificationEventCatalogue::DOCUMENT_REFUSED,
            reference: $document,
        );
    }
}
