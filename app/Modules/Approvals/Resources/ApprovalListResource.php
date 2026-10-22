<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Resources;

use App\Modules\Approvals\Models\Approval;
use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\HR\Resources\EmployeeStub;
use App\Shared\Support\RequestRowScopes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of HR's list of approvals (parity audit C-4): the request, who
 * filed it and in which unit, where it stands, and — while it is pending —
 * whose turn it is, so a request stuck on an absent approver shows at a
 * glance. Opening a row is the live request screen, GET /api/approvals/{id},
 * where it is reassigned.
 *
 * ApprovalListController eager-loads everything read here.
 *
 * @mixin Approval
 */
class ApprovalListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $order = $this->currentOrder();
        $unit = $this->requester?->department;

        return [
            'id'           => $this->id,
            'status'       => $this->status->value,
            'status_label' => $this->status->label(),
            'subject'      => ApprovalSubjectStub::of($this->resource),
            'requester'    => EmployeeStub::from($this->requester),
            'department'   => $unit === null ? null : ['id' => (int) $unit->getKey(), 'name' => $unit->name],
            'submitted_at' => $this->created_at?->toIso8601String(),
            'decided_at'   => $this->decided_at?->toIso8601String(),

            // Whose turn it is now; empty once decided.
            'current_steps' => $this->steps
                ->filter(static fn (ApprovalStep $step): bool => $step->isPending() && $step->step_order === $order)
                ->map(static fn (ApprovalStep $step): array => [
                    'id'         => $step->id,
                    'step_order' => $step->step_order,
                    'approver'   => EmployeeStub::from($step->approver),
                ])->values()->all(),

            'can_reassign' => $this->isPending()
                && RequestRowScopes::for($request, 'approvals.reassign')->covers($unit === null ? null : (int) $unit->getKey()),
        ];
    }
}
