<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Approvals\Enums\Decision;
use App\Modules\Approvals\Exceptions\ApprovalException;
use App\Modules\Approvals\Models\Approval;
use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Approvals\Services\ApprovalService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\NotificationService;
use App\Modules\Departments\Models\Department;
use App\Modules\Documents\Enums\Confirmation;
use App\Modules\Documents\Enums\MarkKind;
use App\Modules\Documents\Exceptions\DocumentDecisionException;
use App\Modules\Documents\Exceptions\SignatureRequiredException;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentMark;
use App\Modules\Documents\Models\EmployeeSignature;
use App\Modules\Documents\Models\UnitStamp;
use App\Modules\Documents\Pdf\DocumentPrinter;
use App\Modules\Documents\Pdf\PdfWorkspace;
use App\Modules\HR\Models\Employee;
use App\Shared\Support\NotificationEventCatalogue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Deciding a document between managers (documents design §7.2).
 *
 * The checks run in the spec's order, so each refusal makes sense: the turn,
 * the unit the signer manages now, its stamp, the signer's signature, the
 * confirmation. Then one transaction: the guard opened for this step around
 * the engine's `act()`, the card, and the rebuilt printed copy. A throw
 * inside it rolls the decision back and deletes the new copy; the previous
 * one is deleted only once the new one is committed. A failure after the
 * commit (an after-commit callback) deletes nothing; a failed commit itself
 * can at worst leave an unreferenced new copy, never a missing committed one.
 *
 * WHO: the route's `can:view,document`, then the engine's turn rule — the
 * caller's own pending step at the approval's current order.
 */
final class DocumentDecisions
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly DecisionGate $gate,
        private readonly UnitManagers $managers,
        private readonly UnitStamps $stamps,
        private readonly Signatures $signatures,
        private readonly DocumentPrinter $printer,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @throws ApprovalException not the caller's turn (the engine's words)
     * @throws DocumentDecisionException
     * @throws SignatureRequiredException
     */
    public function decide(Document $document, User $actor, Decision $decision, ?string $note, Confirmation $by, ?string $password): Document
    {
        $step = $this->currentStepOf($document, $actor);
        $signer = Employee::query()->findOrFail($step->approver_id);

        $unit = $this->managers->unitOf($signer)
            ?? throw new DocumentDecisionException('لا تدير وحدة تنظيمية حالياً، فلا يمكنك التوقيع على المستندات. يُرجى مراجعة مدير النظام لإعادة إسناد الخطوة.');

        $stamp = $this->stamps->current($unit) ?? $this->refuseForMissingStamp($signer, $unit);

        $signature = $this->signatures->current($signer) ?? throw new SignatureRequiredException;

        if ($by === Confirmation::Password && ! Hash::check((string) $password, (string) $actor->password)) {
            throw new DocumentDecisionException('كلمة المرور غير صحيحة.');
        }

        DB::transaction(function () use ($document, $actor, $decision, $note, $by, $step, $signer, $unit, $stamp, $signature): void {
            $approval = $this->gate->through(
                (int) $step->getKey(),
                fn (): Approval => $this->approvals->act($step, $actor, $decision, $note),
            );

            // The engine told the document its outcome on its own copy.
            $document->refresh();

            $this->addMark($document, $approval, $step, $decision, $signer, $unit, $stamp, $signature, $by);

            // The printer writes nothing when it throws.
            $printed = $this->printer->print($document);

            // Inside the transaction, not around it: Laravel runs after-commit
            // callbacks (the push jobs) once the commit is done, still inside
            // transaction(), and a throw from there must not delete the copy
            // the committed decision names.
            try {
                $previous = $document->printed_path;
                $document->update(['printed_path' => $printed]);

                if ($previous !== null) {
                    DB::afterCommit(static fn () => Storage::disk(PdfWorkspace::DISK)->delete($previous));
                }

                if ($decision === Decision::Reject && $approval->isPending()) {
                    $this->tellCreatorOfRejection($document, $signer);
                }
            } catch (Throwable $e) {
                Storage::disk(PdfWorkspace::DISK)->delete($printed);

                throw $e;
            }
        });

        return $document;
    }

    /**
     * The caller's pending step at the approval's current order, as the
     * engine reads a turn. With none — a closed approval, a step decided or
     * not yet reached — the refusal is the engine's turn wording, given here
     * and not borrowed from `act()`, so a retry of the final decision reads
     * the same as any other.
     *
     * @throws ApprovalException
     */
    private function currentStepOf(Document $document, User $actor): ApprovalStep
    {
        $approval = $document->approval()->with('steps')->first();
        $employeeId = $actor->employee_id === null ? null : (int) $actor->employee_id;

        if ($approval === null || $employeeId === null || ! $approval->isPending()) {
            throw new ApprovalException('ليس دورك في اعتماد هذا الطلب.');
        }

        $order = $approval->currentOrder();

        $current = $approval->steps->first(static fn (ApprovalStep $step): bool => $step->isPending()
            && $step->step_order === $order
            && (int) $step->approver_id === $employeeId);

        return $current ?? throw new ApprovalException('ليس دورك في اعتماد هذا الطلب.');
    }

    /**
     * D21.2: the step stays pending, and the system administrators are told
     * once per unit until a stamp is uploaded (`UnitStamps::replace()` forgets
     * the key).
     *
     * @throws DocumentDecisionException
     */
    private function refuseForMissingStamp(Employee $signer, Department $unit): never
    {
        if (Cache::add(UnitStamps::missingKey($unit), true)) {
            $admins = Role::query()->where('name', User::SUPER_ADMIN_ROLE)->first();

            if ($admins !== null) {
                $this->notifications->sendToRole(
                    $admins,
                    'ختم وحدة غير مرفوع',
                    "حاول {$signer->full_name} التوقيع باسم «{$unit->name}» ولا ختم لها بعد.",
                    type: NotificationEventCatalogue::DOCUMENT_STAMP_MISSING,
                    reference: $unit,
                );
            }
        }

        throw new DocumentDecisionException("لم يُرفع الختم الرسمي لوحدتك «{$unit->name}» بعد، ولا يمكن التوقيع قبل رفعه. تم إبلاغ مدير النظام.");
    }

    /**
     * The card, after the engine recorded the decision: the next position
     * is read under the approval's lock, which `act()` holds to the commit.
     */
    private function addMark(
        Document $document,
        Approval $approval,
        ApprovalStep $step,
        Decision $decision,
        Employee $signer,
        Department $unit,
        UnitStamp $stamp,
        EmployeeSignature $signature,
        Confirmation $by,
    ): DocumentMark {
        /** @var ApprovalStep $decided */
        $decided = $approval->steps->firstWhere('id', $step->getKey());

        return DocumentMark::create([
            'document_id'      => $document->getKey(),
            'position'         => (int) $document->marks()->max('position') + 1,
            'kind'             => $decision === Decision::Approve ? MarkKind::ApprovalCard : MarkKind::RejectionCard,
            'approval_step_id' => $decided->getKey(),
            'employee_id'      => $signer->getKey(),
            'employee_name'    => $signer->full_name,
            'unit_id'          => $unit->getKey(),
            'unit_name'        => $unit->name,
            'stamp_id'         => $stamp->getKey(),
            'signature_id'     => $signature->getKey(),
            'decisive'         => $decided->decisive,
            'note'             => $decided->note,
            'confirmed_by'     => $by,
            'marked_at'        => $decided->acted_at ?? now(),
        ]);
    }

    /**
     * A manager's rejection that does not end the document (spec §7.2 step
     * 7): the engine tells the subject only at the end, so this endpoint
     * tells the creator now.
     */
    private function tellCreatorOfRejection(Document $document, Employee $signer): void
    {
        $account = User::query()->where('employee_id', $document->creator_id)->value('id');

        if ($account === null) {
            return;
        }

        $this->notifications->sendToUser(
            (int) $account,
            'تم رفض المستند من مدير',
            "رفض {$signer->full_name} المستند «{$document->title}»، وما زال المستند في مساره.",
            type: NotificationEventCatalogue::DOCUMENT_REJECTED_BY_MANAGER,
            reference: $document,
        );
    }
}
