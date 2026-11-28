<?php

declare(strict_types=1);

namespace App\Modules\Documents\Resources;

use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Auth\Models\User;
use App\Modules\Documents\Enums\DocumentKind;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\Document;
use App\Shared\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A document, as every endpoint of the module shows it (documents design §16).
 * Rendered from a document loaded with `RELATIONS`, so it queries nothing.
 *
 * WHAT THE CALLER MAY DO, so a client draws only the buttons that work:
 *
 * - `my_step_id` / `can.decide` — the caller's own pending step at the
 *   approval's current order: their turn, as the engine reads it.
 * - `can.delete` — the creator (or the system administrator), while the
 *   document is pending and no approver has decided, or, sent to an
 *   employee, while it awaits their signature.
 * - `can.comment` — whoever reads it but its recipient.
 * - `can.answer` — the recipient, while the signature is awaited.
 *
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    /**
     * What a document must have loaded to be rendered. The template's id and
     * name only: its page and fields are not printed here.
     */
    public const RELATIONS = [
        'creator',
        'creatorUnit',
        'recipient',
        'template:id,name',
        'approval.steps.approver',
        'attachments',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        $employeeId = $user?->employee_id === null ? null : (int) $user->employee_id;

        $approval = $this->approval;
        $steps = $approval?->steps ?? collect();
        $currentOrder = $approval?->currentOrder();

        $mine = $employeeId === null ? null : $steps->first(
            static fn (ApprovalStep $step): bool => $step->isPending()
                && $step->step_order === $currentOrder
                && (int) $step->approver_id === $employeeId,
        );

        $sent = $this->kind === DocumentKind::ToEmployee;
        $awaiting = $this->status === DocumentStatus::AwaitingSignature;

        // Deletable while nothing has answered: between managers, no step has
        // decided; to an employee, the signature is still awaited (D31).
        $undecided = $sent
            ? $awaiting
            : $this->status->value === 1 && ! $steps->contains(static fn (ApprovalStep $step): bool => $step->status->isDecision());

        return [
            'id'               => $this->id,
            'kind'             => $this->kind->value,
            'origin'           => $this->origin->value,
            'title'            => $this->title,
            'description'      => $this->description,
            'status'           => ['value' => $this->status->value, 'label' => $this->status->label()],
            'import_number'    => $this->import_number,
            // A template document's (§8.4); an upload's are null.
            'export_number'    => $this->export_number,
            'reference'        => $this->reference,
            // The template it was made from, a deleted one included, and the
            // version whose page was printed (not the template's current one).
            'template'         => $this->template === null ? null : [
                'id'      => $this->template->id,
                'name'    => $this->template->name,
                'version' => $this->template_version,
            ],
            // An object keyed by field key, `{}` when the template has no
            // fields (an empty PHP array would be served as `[]`).
            'field_values'     => $this->field_values === null ? null : (object) $this->field_values,
            'creator'          => $this->creator === null ? null : ['id' => $this->creator->id, 'full_name' => $this->creator->full_name],
            'creator_unit'     => $this->creatorUnit === null ? null : ['id' => $this->creatorUnit->id, 'name' => $this->creatorUnit->name],
            'created_at'       => $this->created_at?->toIso8601String(),
            'decided_at'       => $this->decided_at?->toIso8601String(),
            // Why the system administrator closed it «لم يُستكمل» (§7.8).
            'closed_reason'    => $this->closed_reason,
            'has_printed_copy' => $this->printed_path !== null,

            // A document sent to an employee (workflow 1); all null between
            // managers.
            'recipient'        => $this->recipient === null ? null : ['id' => $this->recipient->id, 'full_name' => $this->recipient->full_name],
            'filing_type'      => $this->filing_type === null ? null : ['value' => $this->filing_type->value, 'label' => $this->filing_type->label()],
            'sent_at'          => $this->sent_at?->toIso8601String(),
            'signed_at'        => $this->signed_at?->toIso8601String(),
            'refused_at'       => $this->refused_at?->toIso8601String(),
            'refusal_reason'   => $this->refusal_reason,

            'steps' => $steps->map(static fn (ApprovalStep $step): array => [
                'id'        => $step->id,
                'order'     => $step->step_order,
                'approver'  => $step->approver === null ? null : ['id' => $step->approver->id, 'full_name' => $step->approver->full_name],
                'status'    => ['value' => $step->status->value, 'label' => $step->status->label()],
                'decisive'  => $step->decisive,
                'note'      => $step->note,
                'acted_at'  => $step->acted_at?->toIso8601String(),
                'skip_reason' => $step->skip_reason?->value,
            ])->values()->all(),

            'attachments' => $this->attachments->map(static fn (Attachment $attachment): array => [
                'id'            => $attachment->id,
                'original_name' => $attachment->original_name,
                'mime_type'     => $attachment->mime_type,
                'size'          => $attachment->size,
            ])->values()->all(),

            'my_step_id' => $mine?->id,
            'can'        => [
                'decide'  => $mine !== null,
                'delete'  => $undecided && $user !== null && $user->can('delete', $this->resource),
                // Never the recipient, even the administrator (the gate's bypass would pass them).
                'comment' => $user !== null && ! $this->isRecipient($user) && $user->can('comment', $this->resource),
                // The recipient alone, and only while the signature is awaited
                // (computed here, not through the gate: the administrator's
                // bypass would pass it).
                'answer'  => $user !== null && $awaiting && $this->isRecipient($user),
            ],
        ];
    }
}
