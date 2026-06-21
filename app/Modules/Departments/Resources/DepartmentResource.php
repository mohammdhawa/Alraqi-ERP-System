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
 * Response shape:
 * {
 *   "id": 1,
 *   "name": "Engineering",
 *   "manager_id": 4,
 *   "created_at": "2026-06-21T10:00:00+00:00",
 *   "updated_at": "2026-06-21T10:00:00+00:00"
 * }
 */
class DepartmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'manager_id' => $this->manager_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
