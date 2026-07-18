<?php

declare(strict_types=1);

namespace App\Modules\Departments\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * DepartmentResource
 *
 * Defines the API contract for a department, decoupling it from the Eloquent
 * model so internal column changes don't leak to clients (architecture report
 * §16.8). Controllers return this instead of raw models.
 *
 * Response shape (a section, level 3, sitting under a division):
 * {
 *   "id": 3,
 *   "name": "Structural Design",
 *   "code": "SD",
 *   "description": "...",
 *   "is_active": true,
 *   "parent_id": 2,
 *   "level": 3,
 *   "level_label": "قسم",
 *   "manager_id": 4,
 *   "created_at": "2026-06-21T10:00:00+00:00",
 *   "updated_at": "2026-06-21T10:00:00+00:00"
 * }
 *
 * WHY level_label ships alongside the raw level: the tier name lives only in
 * the PHP enum (never in the database), so clients cannot resolve 3 -> "قسم"
 * on their own without duplicating the tier table in the frontend — which is
 * the same drift the single-source-of-truth `level` column exists to avoid.
 * `level` stays in the payload as the machine-readable value to filter on.
 */
class DepartmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'code'        => $this->code,
            'description' => $this->description,
            'is_active'   => $this->is_active,
            'parent_id'   => $this->parent_id,
            'level'       => $this->level?->value,
            'level_label' => $this->level?->label(),
            'manager_id'  => $this->manager_id,
            'created_at'  => $this->created_at?->toIso8601String(),
            'updated_at'  => $this->updated_at?->toIso8601String(),
        ];
    }
}
