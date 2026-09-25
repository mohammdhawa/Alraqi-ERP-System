<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Departments\Models\Department;

/**
 * GrantScopeGuard — the single owner of "is this a well-formed scope?".
 *
 * Two rules, each stated once: `scope_department_id` is set EXACTLY WHEN the
 * scope is a named unit, and that unit is live; and super_admin is granted
 * company-wide or not at all.
 *
 * WHY A MODEL-LAYER GUARD AND NOT ONLY A FORM REQUEST: seeders, the importer and
 * tests write `user_roles` around every FormRequest. This project has already
 * paid for rules that lived only at the HTTP door (`DepartmentRequest`'s tier
 * rules let a seeder create four roots). A unit grant with no unit would resolve
 * to an empty scope and look like a bug in the resolver; a company grant that
 * also carries a unit would mean two different things to two different readers.
 *
 * Primitives in, violations out (C5). `UserRole`'s `saving` hook throws on the
 * first one; `AssignRoleRequest` validates the same shape field by field.
 */
final class GrantScopeGuard
{
    /**
     * `$superAdminRole` says whether the role being granted is the built-in
     * super_admin, which may only be granted company-wide: its power is the
     * Gate bypass, and a bypass has no scope. A super_admin grant "over one
     * unit" would be reported as limited and act as unlimited.
     *
     * @return list<GrantScopeViolation>
     */
    public function violations(?int $scopeType, ?int $departmentId, bool $superAdminRole = false): array
    {
        if ($scopeType === null || ! ScopeType::isDefined($scopeType)) {
            return [new GrantScopeViolation('scope_type', 'نطاق الصلاحية المحدد غير معرّف.')];
        }

        $type = ScopeType::from($scopeType);

        if ($superAdminRole && $type !== ScopeType::Company) {
            return [new GrantScopeViolation('scope_type', sprintf(
                'دور مدير النظام لا يُمنح إلا على نطاق «%s».',
                ScopeType::Company->label(),
            ))];
        }

        if (! $type->requiresDepartment()) {
            return $departmentId === null
                ? []
                : [new GrantScopeViolation('scope_department_id', sprintf(
                    'لا يُحدَّد وحدة تنظيمية لنطاق «%s»؛ الوحدة تُحدَّد لنطاق «%s» فقط.',
                    $type->label(),
                    ScopeType::Unit->label(),
                ))];
        }

        if ($departmentId === null) {
            return [new GrantScopeViolation('scope_department_id', 'الوحدة التنظيمية مطلوبة عندما يكون النطاق وحدة محددة.')];
        }

        // The default query honours SoftDeletes: a grant into a deleted unit is
        // refused.
        if (! Department::whereKey($departmentId)->exists()) {
            return [new GrantScopeViolation('scope_department_id', 'الوحدة التنظيمية المحددة غير موجودة أو تم حذفها.')];
        }

        return [];
    }
}
