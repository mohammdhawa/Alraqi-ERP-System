<?php

declare(strict_types=1);

namespace App\Modules\Leave\Resources;

use App\Modules\Leave\Models\LeaveType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One leave type, as the pickers and the catalogue screen read it — every
 * flag the leave form acts on: whether a reason or a file is required, and
 * whether the days come out of the balance.
 *
 * @mixin LeaveType
 */
class LeaveTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'code'                => $this->code,
            'name_ar'             => $this->name_ar,
            'name_en'             => $this->name_en,
            'deducts_balance'     => $this->deducts_balance,
            'for_requests'        => $this->for_requests,
            'for_excuses'         => $this->for_excuses,
            'requires_reason'     => $this->requires_reason,
            'requires_attachment' => $this->requires_attachment,
            'is_active'           => $this->is_active,
            'created_at'          => $this->created_at?->toIso8601String(),
            'updated_at'          => $this->updated_at?->toIso8601String(),
        ];
    }
}
