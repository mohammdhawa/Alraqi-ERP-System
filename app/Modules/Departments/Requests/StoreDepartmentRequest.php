<?php

declare(strict_types=1);

namespace App\Modules\Departments\Requests;

use App\Modules\Departments\Models\Department;

/**
 * StoreDepartmentRequest
 *
 * Creating an organizational unit. The hierarchy rules that apply on create —
 * all owned by DepartmentHierarchyGuard and run by the base request:
 *
 *   1. Root rule       parent_id null => level must be الإدارة العامة (1) AND
 *                      no other root may already exist (singleton root).
 *   2. Child rule      parent_id set  => level must be parent.level + 1.
 *   3. Defined-level   level must map to a DepartmentLevel case.
 *   4. Parent validity parent must exist and not be soft-deleted.
 *
 * Rules 5 (self-reference) and 6 (cycles) do not apply here: a row that does not
 * exist yet cannot point at itself or at its own descendants. They apply on
 * update (see UpdateDepartmentRequest), where a subject row exists.
 *
 * Nothing is create-only beyond `name` and `level` being required rather than
 * optional — the field rules and the guard are inherited from DepartmentRequest.
 */
class StoreDepartmentRequest extends DepartmentRequest
{
    protected function presence(): string
    {
        return 'required';
    }

    /**
     * No subject on create: there is no existing row to fall back to, so the
     * submitted level and parent_id are the effective ones by definition.
     */
    protected function subject(): ?Department
    {
        return null;
    }
}
