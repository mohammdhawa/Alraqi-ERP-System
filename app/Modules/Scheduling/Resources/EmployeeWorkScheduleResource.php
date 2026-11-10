<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Resources;

use App\Modules\Scheduling\Models\EmployeeWorkSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmployeeWorkSchedule */
class EmployeeWorkScheduleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $this->loadMissing(['schedule', 'createdBy']);

        return [
            'id'               => $this->id,
            'work_schedule'    => [
                'id'         => $this->schedule->id,
                'code'       => $this->schedule->code,
                'name'       => $this->schedule->name,
                'kind'       => $this->schedule->kind->value,
                'kind_label' => $this->schedule->kind->label(),
            ],
            'from_date'        => $this->from_date->toDateString(),
            'to_date'          => $this->to_date?->toDateString(),
            'cycle_start_date' => $this->cycle_start_date?->toDateString(),
            'created_by'       => $this->createdBy === null ? null : ['id' => $this->createdBy->id, 'name' => $this->createdBy->name],
        ];
    }
}
