<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Resources;

use App\Modules\Scheduling\Services\ShiftSwapPlan;
use App\Modules\Scheduling\Services\ShiftSwapService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A duty exchange (work shifts piece 2, spec §6.1, §6.4). Each duty is shown
 * as it is worked now. `my_role` is the caller's part in it on /api/me, and
 * null on HR's routes.
 */
class ShiftSwapResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        [$requesterDuty, $partnerDuty] = app(ShiftSwapPlan::class)->shown($this->resource);
        $me = $request->routeIs('me.*') ? (int) $request->user()?->employee_id : null;

        return [
            'id'             => $this->id,
            'status'         => $this->status->value,
            'status_label'   => $this->status->label(),
            'reason'         => $this->reason,
            'requester'      => ['id' => $this->requester->id, 'name' => $this->requester->full_name],
            'partner'        => ['id' => $this->partner->id, 'name' => $this->partner->full_name],
            'requester_duty' => $requesterDuty === null ? ['date' => $this->requester_duty_date->toDateString()] : ShiftSwapService::present($requesterDuty),
            'partner_duty'   => $partnerDuty === null ? ['date' => $this->partner_duty_date->toDateString()] : ShiftSwapService::present($partnerDuty),
            'approval_id'    => $this->approval?->id,
            'decided_at'     => $this->decided_at?->toIso8601String(),
            'cancelled_at'   => $this->cancelled_at?->toIso8601String(),
            'cancel_reason'  => $this->cancel_reason,
            'created_at'     => $this->created_at?->toIso8601String(),
            'my_role'        => match (true) {
                $me === null                         => null,
                $me === (int) $this->requester_id    => 'requester',
                $me === (int) $this->partner_id      => 'partner',
                default                              => null,
            },
        ];
    }
}
