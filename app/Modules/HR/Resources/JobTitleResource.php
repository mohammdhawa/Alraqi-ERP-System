<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JobTitleResource
 *
 * The API contract for a catalogue entry.
 *
 * `default_activity_type` ships with its label for the same reason
 * `level_label` does on departments: the Arabic name lives only in the PHP
 * enum, so a client that received the bare int would have to keep its own copy
 * of the table — which is the drift the int column exists to prevent.
 *
 * NO `department_id`, and no employee count. The first does not exist (one
 * shared catalogue, ratified 2026-09-13). The second is a query a client should
 * ask for deliberately rather than pay for on every row of every page.
 */
class JobTitleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                           => $this->id,
            'name'                         => $this->name,
            'default_organizational_grade' => $this->default_organizational_grade,
            'default_activity_type'        => $this->default_activity_type?->value,
            'default_activity_type_label'  => $this->default_activity_type?->label(),
            'is_described'                 => $this->is_described,
            'description'                  => $this->description,
            'is_active'                    => $this->is_active,
            'created_at'                   => $this->created_at?->toIso8601String(),
            'updated_at'                   => $this->updated_at?->toIso8601String(),
        ];
    }
}
