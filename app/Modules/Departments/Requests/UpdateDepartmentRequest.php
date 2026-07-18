<?php

declare(strict_types=1);

namespace App\Modules\Departments\Requests;

use App\Modules\Departments\Models\Department;

/**
 * UpdateDepartmentRequest
 *
 * Updating an organizational unit. It differs from StoreDepartmentRequest in
 * exactly one input: there is now a row being moved, so `subject()` returns it
 * (create returns null). That subject is what lets DepartmentHierarchyGuard
 * apply the two rules that only exist once a row can move:
 *
 *   5. No self-reference  parent_id may not be the row's own id.
 *   6. No cycles          parent_id may not be one of the row's descendants.
 *
 * WHY rule 6 matters even though rule 2 already constrains the level:
 * - Rule 2 blocks most cycles as a side effect (a descendant sits below you, so
 *   the tier maths usually will not add up). But with three tiers defined, a
 *   move can satisfy parent.level + 1 and still close a loop — e.g. re-parenting
 *   a level-2 division under a level-3 section inside its own subtree. A loop
 *   detaches the whole branch: it is unreachable from the root, yet every row
 *   still validates. The guard walks ancestors to catch it.
 *
 * All of that logic lives in DepartmentHierarchyGuard (invoked by the base
 * `after()`), so this class carries no rule code — only `presence()` and the
 * subject. Fields are `sometimes`, so a PATCH that only renames a unit need not
 * resend level and parent_id; the base's effective* helpers fall back to the
 * row's current values, keeping the tier rules honest on partial updates.
 */
class UpdateDepartmentRequest extends DepartmentRequest
{
    protected function presence(): string
    {
        return 'sometimes';
    }

    /**
     * The row being updated, via route-model binding (/api/departments/{department}).
     */
    protected function subject(): ?Department
    {
        $department = $this->route('department');

        return $department instanceof Department ? $department : null;
    }
}
