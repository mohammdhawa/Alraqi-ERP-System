<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkSiteAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->whenLoaded('employee', fn () => $this->employee?->full_name),
            'work_site_id' => $this->work_site_id,
            'site_name' => $this->whenLoaded('site', fn () => $this->site?->name),
            'effective_from' => $this->effective_from->toIso8601String(),
            'effective_to' => $this->effective_to?->toIso8601String(),
        ];
    }
}
