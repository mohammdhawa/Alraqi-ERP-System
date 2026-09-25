<?php

declare(strict_types=1);

namespace App\Shared\Services;

use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Support\PermissionCache;
use App\Modules\Departments\Models\Department;
use App\Shared\Support\RowScope;

/**
 * AccessScope — for a user and a permission, "all rows" or "these units" (GAP-3).
 *
 * THE RULES, each ratified (START_HERE §9, decisions 14 and 18):
 *
 *   super_admin → all. It must keep bypassing: a scoped super admin is a
 *                 locked-out administrator. Checked first, before any grant.
 *   company     → all.
 *   unit        → the subtree of `scope_department_id`.
 *   managed     → the subtree of every unit whose `departments.manager_id` is
 *                 this user's employee. The tree stays the single record of
 *                 who manages what (P1); a departed employee (soft-deleted)
 *                 manages nothing, the same reading ManagementChain gives a
 *                 pointer at someone who has left.
 *   several     → the UNION.
 *
 * ⚙️ SCOPED PER PERMISSION, NOT PER USER. Only the grants whose role carries
 * the permission being exercised count. An HR clerk may hold `hr.employees.view`
 * company-wide through one role and `hr.employees.update` over one section
 * through another; asking "may they edit this person" must not borrow the reach
 * of the viewing grant. A union across every grant regardless of permission
 * would quietly do exactly that.
 *
 * FAILS CLOSED: a user holding no grant that carries the permission resolves
 * to an empty unit set, which reaches nothing. Route middleware normally stops
 * them first; this is the answer if something ever calls in without it.
 *
 * WHERE IT LIVES: `app/Shared`, because every module that scopes rows needs the
 * same answer — HR today, Departments and the approvals engine after it — and
 * two copies of "how far does this grant reach" would drift.
 *
 * COST: the grants come from `PermissionCache` (cached, version-invalidated).
 * The tree is read fresh — one query for managed units, then one per tier — and
 * only for a caller who is not already unrestricted.
 */
class AccessScope
{
    public function for(User $user, string $permission): RowScope
    {
        if ($user->isSuperAdmin()) {
            return RowScope::all();
        }

        $roots = [];
        $managed = false;

        foreach (PermissionCache::grants($user) as $grant) {
            if (! in_array($permission, $grant['permissions'], true)) {
                continue;
            }

            switch (ScopeType::tryFrom($grant['scope_type'])) {
                case ScopeType::Company:
                    return RowScope::all();

                case ScopeType::Unit:
                    if ($grant['department_id'] !== null) {
                        $roots[] = $grant['department_id'];
                    }
                    break;

                case ScopeType::Managed:
                    $managed = true;
                    break;

                default:
                    // An undefined type grants nothing. The guard refuses to
                    // write one and PermissionCache drops a hand-edited one;
                    // this arm is the backstop behind both.
                    break;
            }
        }

        if ($managed) {
            $roots = array_merge($roots, $this->managedUnitIds($user));
        }

        return RowScope::units($roots === [] ? [] : Department::subtreeIds($roots));
    }

    /**
     * Whether the user's grant for this permission reaches EVERY row — a super
     * admin, or a company-wide grant carrying it.
     *
     * For surfaces that are not row-scoped yet and hold rows that are: the
     * audit trail keeps a copy of every employee's history, so reading it
     * through an employee permission has to mean the whole company, or a
     * section-scoped grant would read the rest of the company's history there.
     *
     * Reads only the cached grants — no tree walk, no query on a warm cache —
     * so it is safe where `hasPermission()` is, a Resource included.
     */
    public function reachesEverything(User $user, string $permission): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        foreach (PermissionCache::grants($user) as $grant) {
            if ($grant['scope_type'] === ScopeType::Company->value && in_array($permission, $grant['permissions'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The units this user's employee manages, directly. `whereHas('manager')`
     * excludes a pointer at a soft-deleted employee: a departed person holds
     * no post.
     *
     * Public because `RoleService` asks it of a GRANTEE: whoever hands out a
     * managed grant must reach the units it would cover today.
     *
     * @return array<int, int>
     */
    public function managedUnitIds(User $user): array
    {
        if ($user->employee_id === null) {
            return [];
        }

        return Department::query()
            ->where('manager_id', $user->employee_id)
            ->whereHas('manager')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
