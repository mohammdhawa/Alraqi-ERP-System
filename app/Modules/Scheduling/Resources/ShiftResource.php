<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Resources;

use App\Modules\Attendance\Support\AttendanceCalendar;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A shift, with the rules in force today (per-shift rules spec §5.1) and —
 * when the caller asks for them, on the one-shift read — its rule versions,
 * newest first.
 */
class ShiftResource extends JsonResource
{
    private bool $withVersions = false;

    public function withVersions(): static
    {
        $this->withVersions = true;

        return $this;
    }

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
            'rules'            => $this->rulesOn(app(AttendanceCalendar::class)->today())->toArray(),
            'rule_versions'    => $this->when(
                $this->withVersions,
                fn () => ShiftRuleResource::collection($this->rules->sortByDesc(fn ($rule) => $rule->effective_from->toDateString())->values())->resolve($request),
            ),
        ];
    }
}
