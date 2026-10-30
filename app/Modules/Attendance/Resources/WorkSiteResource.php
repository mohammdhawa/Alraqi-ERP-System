<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkSiteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_active' => $this->archived_at === null,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'versions' => $this->whenLoaded('versions', fn () => $this->versions->map(fn ($v) => [
                'id' => $v->id,
                'name' => $v->name,
                'latitude' => $v->latitude,
                'longitude' => $v->longitude,
                'radius_m' => $v->radius_m,
                'effective_from' => $v->effective_from->toIso8601String(),
                'effective_to' => $v->effective_to?->toIso8601String(),
            ])->values()->all()),
        ];
    }
}
