<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One dated version of a shift's rules (per-shift rules spec §5.1). */
class ShiftRuleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'effective_from' => $this->effective_from->toDateString(),
        ] + $this->toValue()->toArray() + [
            'created_by' => $this->createdBy === null ? null : ['id' => $this->createdBy->id, 'name' => $this->createdBy->employee?->full_name],
        ];
    }
}
