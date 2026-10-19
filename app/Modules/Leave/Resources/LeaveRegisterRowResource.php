<?php

declare(strict_types=1);

namespace App\Modules\Leave\Resources;

use Illuminate\Http\Request;

/**
 * A register row: the leave, plus how much of it falls inside the window —
 * `days_in_range` (working days) and `calendar_days_in_range` (the raw span),
 * the same two figures a leave already carries as `requested_days` and its
 * dates (legacy). LeaveRegister sets both on the row before it is rendered.
 */
class LeaveRegisterRowResource extends LeaveRequestResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'days_in_range'          => (int) $this->resource->getAttribute('days_in_range'),
            'calendar_days_in_range' => (int) $this->resource->getAttribute('calendar_days_in_range'),
        ];
    }
}
