<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Resources;

use App\Modules\Scheduling\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Shift */
class ShiftResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'code'             => $this->code,
            'name'             => $this->name,
            'starts_at'        => $this->starts_at,
            'duration_minutes' => $this->duration_minutes,
            'ends_next_day'    => $this->endsNextDay(),
            'is_active'        => $this->is_active,
        ];
    }
}
