<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Resources;

use App\Modules\Approvals\Models\Approval;
use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Resources\EmployeeStub;
use App\Shared\Support\Actor;
use App\Shared\Support\RequestRowScopes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The live state of one approval — the request screen in the app and the
 * dashboard (design §7, 2026-10-14). Built from a fresh read on every call;
 * a client never acts on a cached copy.
 *
 * WHAT THE CALLER MAY DO, so a client draws only the buttons that work:
 *
 * - `can_act` / `current_step_id` — the caller's own pending step at the
 *   current order, the engine's "whose turn" rule. Never true for being an
 *   administrator (legacy).
 * - `can_withdraw` — the requester, while no step is decided (§9 Q1 = B).
 * - `can_reassign` — `approvals.reassign` reaching the requester's current
 *   unit (§9 Q4); read from the request's memo, which the controller warms.
 *
 * Every path renders an approval loaded with ApprovalRelations::liveState(),
 * so nothing here queries.
 *
 * @mixin Approval
 */
class ApprovalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        $employeeId = $user?->employee_id === null ? null : (int) $user->employee_id;

        $currentOrder = $this->currentOrder();
        $mine = $employeeId === null ? null : $this->steps->first(
            static fn (ApprovalStep $step): bool => $step->isPending()
                && $step->step_order === $currentOrder
                && (int) $step->approver_id === $employeeId,
        );

        $unit = $this->requester?->department_id;

        return [
            'id'               => $this->id,
            'status'           => $this->status->value,
            'status_label'     => $this->status->label(),
            'rejection_policy' => $this->rejection_policy->value,
            'subject'          => ApprovalSubjectStub::of($this->resource),
            'requested_by'     => $this->requested_by,
            'requester'        => EmployeeStub::from($this->requester),
            'submitted_at'     => $this->created_at?->toIso8601String(),
            'decided_at'       => $this->decided_at?->toIso8601String(),

            'current_order'   => $currentOrder,
            'current_step_id' => $mine?->id,
            'can_act'         => $mine !== null,
            'can_withdraw'    => $this->isPending()
                && $employeeId !== null
                && $employeeId === (int) $this->requested_by
                && ! $this->steps->contains(static fn (ApprovalStep $step): bool => $step->status->isDecision()),
            'can_reassign' => $this->isPending()
                && $user !== null
                && RequestRowScopes::for($request, 'approvals.reassign')->covers($unit === null ? null : (int) $unit),

            'steps' => $this->steps->map(static fn (ApprovalStep $step): array => [
                'id'                => $step->id,
                'step_order'        => $step->step_order,
                'resolver'          => $step->resolver->value,
                'resolver_label'    => $step->resolver->label(),
                'resolver_ref'      => $step->resolver_ref,
                'approver_id'       => $step->approver_id,
                'approver'          => EmployeeStub::from($step->approver),
                'decisive'          => $step->decisive,
                'status'            => $step->status->value,
                'status_label'      => $step->status->label(),
                'is_current'        => $step->isPending() && $step->step_order === $currentOrder,
                'skip_reason'       => $step->skip_reason?->value,
                'skip_reason_label' => $step->skip_reason?->label(),
                'acted_by'          => Actor::of($step->actedBy),
                'acted_at'          => $step->acted_at?->toIso8601String(),
                'note'              => $step->note,
            ])->values()->all(),

            'reassignments' => ApprovalReassignmentResource::collection($this->reassignments)->resolve($request),
        ];
    }
}
