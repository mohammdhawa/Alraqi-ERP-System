<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Models\UserRole;
use Illuminate\Support\Facades\Cache;

/**
 * PermissionCache
 *
 * Caches each user's resolved RBAC state so the CheckPermission middleware does
 * not run a query on every request. The tricky part of any permission cache is
 * invalidation; this uses a single GLOBAL VERSION STAMP to make it trivial and
 * always correct:
 *
 *   - Every cache key embeds the current `rbac:version` (see keyFor()).
 *   - Any RBAC mutation — role assign/unassign, role create/update/delete,
 *     permission catalogue changes — calls flush(), which bumps that version.
 *   - Bumping the version instantly orphans EVERY user's cached entry at once;
 *     the next read recomputes. No per-user key enumeration, and no stale reads.
 *
 * The trade-off of a global bump is that one RBAC change re-warms everyone's
 * entry lazily — fine for an ERP's change rate, and far safer than trying to
 * compute exactly which users a role/permission edit touched.
 *
 * INVARIANT: every write path that changes what a user may do must go through
 * a service that calls flush() (RoleService does). Code that manipulates the
 * user_roles / role_permissions pivots directly (tests, one-off scripts) must
 * flush() itself, or reads may be stale until the TTL lapses.
 *
 * PROD NOTE: like any cross-request cache, correctness across web workers needs
 * a SHARED cache store (database/redis/memcached). The array driver used in
 * tests is per-process — fine there because each test boots a fresh app.
 */
final class PermissionCache
{
    /**
     * The key holding the global RBAC version counter.
     */
    private const VERSION_KEY = 'rbac:version';

    /**
     * How long a user's resolved entry lives before it is recomputed anyway.
     * The version stamp makes this a backstop, not the primary invalidation.
     */
    private const TTL_SECONDS = 3600;

    /**
     * The current RBAC version. Defaults to 1 before anything has flushed.
     */
    public static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    /**
     * Invalidate every user's cached permission set by bumping the version.
     * Call after ANY change to roles, permissions, or their assignments.
     */
    public static function flush(): void
    {
        // Seed the counter if absent (add is a no-op when present), then bump —
        // so an absent key moves 1 -> 2 and actually changes the stamp, rather
        // than incrementing from 0 to 1 (which version() already reports).
        Cache::add(self::VERSION_KEY, 1);
        Cache::increment(self::VERSION_KEY);
    }

    /**
     * Whether the user is a super admin (holds the built-in super_admin role).
     */
    public static function isSuperAdmin(User $user): bool
    {
        return self::resolve($user)['is_super'];
    }

    /**
     * The permission names the user effectively holds. A super admin resolves to
     * the ENTIRE catalogue (so the UI can render their full set), even though the
     * super_admin role carries no explicit permission rows — its power is the
     * Gate::before bypass, not stored grants.
     *
     * @return array<int, string>
     */
    public static function names(User $user): array
    {
        return self::resolve($user)['names'];
    }

    /**
     * The user's grants, each reduced to what the scope resolver needs: how far
     * it reaches and which permissions ride on it (GAP-3).
     *
     * A super admin resolves to NO grants on purpose. Their reach is the bypass,
     * never a scope — `AccessScope` checks `isSuperAdmin()` first, and a scoped
     * super admin would be a locked-out administrator.
     *
     * ⚙️ CACHED FOR THE SAME REASON AS `names()`, AND INVALIDATED THE SAME WAY.
     * A scope is RBAC state: `RoleService::assign/unassign` bump the version,
     * so a revoked scope stops applying on the very next request rather than
     * when the hour-long TTL lapses. What is cached is the GRANT (type + unit),
     * never the resolved department ids — the tree beneath a unit, and who
     * manages what, are read fresh on each request, so moving a manager or
     * re-parenting a section needs no cache flush to take effect.
     *
     * @return array<int, array{scope_type: int, department_id: int|null, permissions: array<int, string>}>
     */
    public static function grants(User $user): array
    {
        return self::resolve($user)['grants'];
    }

    /**
     * Resolve (and cache) the user's RBAC state under the version-stamped key.
     *
     * The permission names are derived FROM the grants rather than queried
     * separately, so the two can never disagree about which roles count.
     *
     * @return array{is_super: bool, names: array<int, string>, grants: array<int, array{scope_type: int, department_id: int|null, permissions: array<int, string>}>}
     */
    private static function resolve(User $user): array
    {
        return Cache::remember(self::keyFor($user), self::TTL_SECONDS, static function () use ($user): array {
            // COMPANY grants only. The guard refuses a narrowed super_admin
            // grant; one that predates that rule, or was written around it,
            // must not become an unlimited bypass that the admin screen
            // reports as limited. It falls through as an ordinary grant of a
            // role with no permission rows, and reaches nothing.
            $isSuper = $user->roles()
                ->where('name', User::SUPER_ADMIN_ROLE)
                ->wherePivot('scope_type', ScopeType::Company->value)
                ->exists();

            if ($isSuper) {
                return [
                    'is_super' => true,
                    'names'    => Permission::query()->pluck('name')->all(),
                    'grants'   => [],
                ];
            }

            // whereHas('role') drops grants of a soft-deleted role, exactly as
            // `$user->roles()` always has. A grant whose stored type is not a
            // ScopeType reads as null and is dropped too: it reaches nothing,
            // and must not take the user's every request down with it.
            $grants = UserRole::query()
                ->where('user_id', $user->getKey())
                ->whereHas('role')
                ->with('role.permissions:id,name')
                ->get()
                ->filter(static fn (UserRole $grant): bool => $grant->scope_type !== null)
                ->map(static fn (UserRole $grant): array => [
                    'scope_type'    => $grant->scope_type->value,
                    'department_id' => $grant->scope_department_id === null ? null : (int) $grant->scope_department_id,
                    'permissions'   => $grant->role->permissions->pluck('name')->all(),
                ])
                ->values()
                ->all();

            $names = array_values(array_unique(array_merge([], ...array_column($grants, 'permissions'))));

            return ['is_super' => false, 'names' => $names, 'grants' => $grants];
        });
    }

    /**
     * ⚙️ The suffix changed from `:perms` to `:rbac` when grants joined the
     * payload (GAP-3). An entry cached by the previous release has no `grants`
     * key, and a shared cache store outlives a deploy — so the old key shape
     * must be orphaned, not read.
     */
    private static function keyFor(User $user): string
    {
        return sprintf('rbac:v%d:user:%d:rbac', self::version(), $user->getKey());
    }
}
