<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Resources;

use App\Modules\Scheduling\Models\WorkSchedule;
use App\Modules\Scheduling\Models\WorkScheduleDay;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A schedule with every position stated: `shift` null is a day off.
 *
 * @mixin WorkSchedule
 */
class WorkScheduleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $this->loadMissing('days.shift');

        return [
            'id'         => $this->id,
            'code'       => $this->code,
            'name'       => $this->name,
            'kind'       => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'cycle_days' => $this->cycle_days,
            'is_active'  => $this->is_active,
            'days'       => $this->days->map(fn (WorkScheduleDay $day): array => [
                'position' => $day->position,
                'shift'    => $day->shift === null ? null : (new ShiftResource($day->shift))->resolve($request),
            ])->values()->all(),
        ];
    }
}
