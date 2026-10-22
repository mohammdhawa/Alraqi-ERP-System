<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Resources;

use App\Modules\Approvals\Models\ApprovalReassignment;
use App\Modules\HR\Resources\EmployeeStub;
use App\Shared\Support\Actor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One hand-off of a step (design §6), as the request screen lists them: from
 * whom, to whom, by which account, and why. Its caller eager-loads the three
 * people (ApprovalRelations::liveState()).
 *
 * @mixin ApprovalReassignment
 */
class ApprovalReassignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'step_id'      => $this->approval_step_id,
            'step_order'   => $this->step_order,
            'from'         => EmployeeStub::from($this->fromEmployee),
            'to'           => EmployeeStub::from($this->toEmployee),
            'performed_by' => Actor::of($this->performedBy),
            'reason'       => $this->reason,
            'created_at'   => $this->created_at?->toIso8601String(),
        ];
    }
}
