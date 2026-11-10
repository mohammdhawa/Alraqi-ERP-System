<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A person's shift on a date (work shifts piece 2, spec §5.1). */
class ShiftChangeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'date'          => $this->date->toDateString(),
            'shift'         => $this->shift === null ? null : new ShiftResource($this->shift),
            'origin'        => $this->origin->value,
            'origin_label'  => $this->origin->label(),
            'shift_swap_id' => $this->shift_swap_id,
            'reason'        => $this->reason,
            'created_by'    => $this->createdBy === null ? null : ['id' => $this->createdBy->id, 'name' => $this->createdBy->employee?->full_name],
            'created_at'    => $this->created_at?->toIso8601String(),
        ];
    }
}
