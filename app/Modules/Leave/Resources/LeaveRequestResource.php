<?php

declare(strict_types=1);

namespace App\Modules\Leave\Resources;

use App\Modules\Approvals\Resources\ApprovalResource;
use App\Modules\HR\Resources\EmployeeStub;
use App\Modules\Leave\Models\LeaveRequest;
use App\Shared\Support\Actor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One leave — a request or an HR excuse — for the employee's app, the
 * approver's request screen and HR's register alike (LEAVE_BUILD.md §4).
 *
 * It carries its APPROVAL's live state (APPROVALS_ENGINE_DESIGN.md §7), so
 * the app needs no second call to draw the chain and its buttons; an excuse
 * has none (`null`). Its files are listed, never linked.
 *
 * Every path renders a row loaded with LeaveRequestQuery::renderRelations().
 *
 * @mixin LeaveRequest
 */
class LeaveRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'employee_id'   => $this->employee_id,
            'employee'      => EmployeeStub::from($this->employee),
            'leave_type_id' => $this->leave_type_id,
            'leave_type'    => [
                'id'              => (int) $this->type->id,
                'code'            => $this->type->code,
                'name_ar'         => $this->type->name_ar,
                'deducts_balance' => $this->type->deducts_balance,
            ],
            'origin'          => $this->origin->value,
            'origin_label'    => $this->origin->label(),
            'is_excuse'       => $this->isExcuse(),
            'deducts_balance' => $this->deducts_balance,
            'reason'          => $this->reason,
            'start_date'      => $this->start_date->toDateString(),
            'end_date'        => $this->end_date->toDateString(),
            'requested_days'  => $this->requested_days,
            'status'          => $this->status->value,
            'status_label'    => $this->status->label(),
            'decided_at'      => $this->decided_at?->toIso8601String(),
            'created_by'      => Actor::of($this->createdBy),

            'cancelled_at'        => $this->cancelled_at?->toIso8601String(),
            'cancelled_by'        => Actor::of($this->cancelledBy),
            'cancellation_reason' => $this->cancellation_reason,

            'returned_to_work_on' => $this->returned_to_work_on?->toDateString(),
            'original_end_date'   => $this->original_end_date?->toDateString(),
            'shortened_at'        => $this->shortened_at?->toIso8601String(),
            'shortened_by'        => Actor::of($this->shortenedBy),
            'shortening_reason'   => $this->shortening_reason,

            'attachments' => AttachmentResource::collection($this->attachments)->resolve($request),
            'approval'    => $this->approval === null ? null : (new ApprovalResource($this->approval))->resolve($request),
            'created_at'  => $this->created_at?->toIso8601String(),
        ];
    }
}
