<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Resources;

use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\HR\Resources\EmployeeStub;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the approver's inbox (design §7): the caller's step, and enough
 * of its request to read the list without opening each one — the subject's
 * summary and the requester (2026-10-18). Tapping a row opens the live
 * request screen by `approval_id`.
 *
 * The inbox eager-loads ApprovalRelations::inbox(), so nothing here queries.
 *
 * @mixin ApprovalStep
 */
class ApprovalInboxResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $approval = $this->approval;

        return [
            'step_id'      => $this->id,
            'approval_id'  => $this->approval_id,
            'step_order'   => $this->step_order,
            'status'       => $this->status->value,
            'status_label' => $this->status->label(),
            'acted_at'     => $this->acted_at?->toIso8601String(),
            'note'         => $this->note,

            'approval_status'       => $approval->status->value,
            'approval_status_label' => $approval->status->label(),
            'subject'               => ApprovalSubjectStub::of($approval),
            'requester'             => EmployeeStub::from($approval->requester),
            'submitted_at'          => $approval->created_at?->toIso8601String(),
        ];
    }
}
