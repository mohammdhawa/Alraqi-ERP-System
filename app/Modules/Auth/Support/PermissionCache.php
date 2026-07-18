<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
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
     * Resolve (and cache) the user's RBAC state under the version-stamped key.
     *
     * @return array{is_super: bool, names: array<int, string>}
     */
    private static function resolve(User $user): array
    {
        return Cache::remember(self::keyFor($user), self::TTL_SECONDS, static function () use ($user): array {
            $isSuper = $user->roles()->where('name', User::SUPER_ADMIN_ROLE)->exists();

            $names = $isSuper
                ? Permission::query()->pluck('name')->all()
                : $user->roles()
                    ->with('permissions:id,name')
                    ->get()
                    ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
                    ->unique()
                    ->values()
                    ->all();

            return ['is_super' => $isSuper, 'names' => $names];
        });
    }

    private static function keyFor(User $user): string
    {
        return sprintf('rbac:v%d:user:%d:perms', self::version(), $user->getKey());
    }
}
