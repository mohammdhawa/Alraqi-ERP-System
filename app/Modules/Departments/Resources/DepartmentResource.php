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
 *   "name": "قسم النقدية",
 *   "code": "FIN-CASH",
 *   "description": "...",
 *   "is_active": true,
 *   "parent_id": 2,
 *   "level": 3,
 *   "level_label": "قسم",
 *   "sector": 1,
 *   "sector_label": "المكاتب الإدارية",
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
 *
 * `sector` / `sector_label` ship for the same reason, one step further: the
 * section above stores NULL, and 1 is its DIVISION's value reached by walking
 * up. Resolving it here is what stops every client rebuilding the tree to
 * render a badge.
 *
 * NOTE, and it is the one asymmetry in this resource: `sector` is a RESOLVED
 * value, so a client that PUTs this object back is posting a sector the row
 * does not store. That write is refused — DepartmentHierarchyGuard allows a
 * stored sector only on an إدارة — rather than silently accepted, because
 * accepting it would plant a copy that survives the section being re-parented
 * and then shadows the true sector from its whole subtree.
 */
class DepartmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'code'         => $this->code,
            'description'  => $this->description,
            'is_active'    => $this->is_active,
            'parent_id'    => $this->parent_id,
            'level'        => $this->level?->value,
            'level_label'  => $this->level?->label(),
            'sector'       => $this->sector?->value,
            'sector_label' => $this->sector?->label(),
            'manager_id'   => $this->manager_id,
            'created_at'   => $this->created_at?->toIso8601String(),
            'updated_at'   => $this->updated_at?->toIso8601String(),
        ];
    }
}
