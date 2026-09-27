<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Auth\Exceptions\GrantOutOfReachException;
use App\Modules\Auth\Exceptions\SystemRoleException;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Models\UserRole;
use App\Modules\Auth\Support\PermissionCache;
use App\Modules\Departments\Models\Department;
use App\Shared\Services\AccessScope;
use App\Shared\Services\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * RoleService
 *
 * Business logic for reading roles and assigning them to users. Granting access
 * is a security-relevant action, so each assignment is recorded in the audit
 * log (§16.14).
 *
 * Every method that changes what a user may do — create/update/delete a role and
 * assign/unassign — calls PermissionCache::flush() so the cached permission sets
 * every request reads are invalidated. Built-in system roles (is_system) are
 * immutable here: update() and delete() refuse them via SystemRoleException, so
 * the RBAC bootstrap cannot be renamed or removed through any caller.
 */
class RoleService
{
    /**
     * The permission whose reach bounds what a role administrator may grant
     * and revoke — the one the assign/unassign routes require.
     *
     * `auth.roles.assign` since 2026-09-26 (GAP-15): split out of
     * `auth.roles.update`, which now means editing role definitions and is
     * company-only. See the note on the routes.
     */
    public const GRANT_PERMISSION = 'auth.roles.assign';

    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly AccessScope $scopes,
    ) {}

    /**
     * A page of roles with their permissions, for admin listing.
     *
     * WAS UNPAGINATED (C27, GAP-11) — see UserService::paginate() for why that
     * mattered. Roles grow more slowly than users, but "there is no unpaginated
     * list endpoint in this API" is the rule precisely so that nobody has to
     * judge which tables will stay small.
     *
     * Ordered by name then id (C29): role names are unique, but the tie-break
     * is uniform across every list query rather than case-by-case.
     *
     * @return LengthAwarePaginator<Role>
     */
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return Role::query()
            ->with('permissions')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage);
    }

    /**
     * Create a role, optionally attaching permissions by name.
     *
     * Wrapped in a transaction so the role and its permission links are written
     * atomically. Creating a role is a security-relevant action, so it's audited.
     *
     * @param  array{name: string, label?: string|null, description?: string|null, permissions?: array<int, string>}  $data
     */
    public function create(array $data): Role
    {
        $role = DB::transaction(function () use ($data): Role {
            // is_system is never accepted from input: API-created roles are
            // always ordinary. Set explicitly (not just via the DB default) so
            // the returned model — and thus the resource — reflects it as false
            // without a reload.
            $role = Role::create([
                'name'        => $data['name'],
                'label'       => $data['label'] ?? null,
                'description' => $data['description'] ?? null,
                'is_system'   => false,
            ]);

            if (! empty($data['permissions'])) {
                $permissionIds = Permission::whereIn('name', $data['permissions'])->pluck('id');
                $role->permissions()->sync($permissionIds);
            }

            $this->auditLogService->logAction(
                event: 'role_created',
                description: "Role '{$role->name}' created.",
            );

            return $role->load('permissions');
        });

        PermissionCache::flush();

        return $role;
    }

    /**
     * Update a role and, if provided, replace its permission set.
     *
     * Only keys present in $data are touched, so partial updates are safe.
     * Passing a `permissions` array syncs (replaces) the role's permissions.
     *
     * A built-in system role (super_admin) is immutable: renaming or re-scoping
     * it would break the RBAC bootstrap, so this refuses before touching it.
     *
     * @param  array{name?: string, label?: string|null, description?: string|null, permissions?: array<int, string>}  $data
     *
     * @throws SystemRoleException if the role is a system role.
     */
    public function update(Role $role, array $data): Role
    {
        $this->guardNotSystem($role);

        $role = DB::transaction(function () use ($role, $data): Role {
            if (array_key_exists('name', $data)) {
                $role->name = $data['name'];
            }
            if (array_key_exists('label', $data)) {
                $role->label = $data['label'];
            }
            if (array_key_exists('description', $data)) {
                $role->description = $data['description'];
            }
            $role->save();

            if (array_key_exists('permissions', $data)) {
                $permissionIds = Permission::whereIn('name', $data['permissions'])->pluck('id');
                $role->permissions()->sync($permissionIds);
            }

            $this->auditLogService->logAction(
                event: 'role_updated',
                description: "Role '{$role->name}' updated.",
            );

            return $role->load('permissions');
        });

        PermissionCache::flush();

        return $role;
    }

    /**
     * Delete a role. The user_roles and role_permissions pivots cascade.
     * A built-in system role cannot be deleted.
     *
     * @throws SystemRoleException if the role is a system role.
     */
    public function delete(Role $role): void
    {
        $this->guardNotSystem($role);

        $name = $role->name;

        $role->delete();

        $this->auditLogService->logAction(
            event: 'role_deleted',
            description: "Role '{$name}' deleted.",
        );

        PermissionCache::flush();
    }

    /**
     * Refuse to mutate a built-in system role.
     *
     * @throws SystemRoleException
     */
    private function guardNotSystem(Role $role): void
    {
        if ($role->is_system) {
            throw new SystemRoleException;
        }
    }

    /**
     * Grant a role to a user over a scope (idempotent — the same grant twice
     * is one row).
     *
     * ⚙️ SCOPED SINCE GAP-3 (decision 18). A grant is role + scope, so the same
     * role over two units is two grants and both are kept. Omitting the scope
     * means COMPANY — the reach every grant had before scopes existed — so a
     * caller written against the old contract keeps getting what it got.
     *
     * The existence check runs under a lock on the user row, because the
     * unique index cannot see two identical company grants as duplicates
     * (NULL ≠ NULL). A real guarantee on MySQL; a no-op under SQLite.
     *
     * The scope's shape (a unit exactly when the type is Unit) is enforced by
     * `UserRole`'s saving hook for every writer; AssignRoleRequest reports the
     * same rules as field-level 422s first.
     *
     * TAKES IDS, NOT MODELS. RoleController used to resolve both rows itself
     * with User::findOrFail() / Role::findOrFail() before calling in, which put
     * repository work in a handler (C3). Loading them is the service's job, so
     * every caller — HTTP, console, a future bulk-grant screen — gets the same
     * lookup and the same failure behaviour.
     *
     * A missing id raises ModelNotFoundException, which the central renderer
     * maps to a generic 404 that names no model class (C12). The FormRequest
     * normally catches this first and returns a friendlier field-level 422.
     *
     * `$grantedBy` is the administrator making the grant, and bounds it: see
     * ensureWithinReach(). Null means the system itself (a seeder, a console
     * command), which answers to no grant. Every HTTP caller passes one.
     *
     * @throws GrantOutOfReachException when the grant reaches further than
     *                                  `$grantedBy` does.
     */
    public function assign(
        int $userId,
        int $roleId,
        ScopeType $scopeType = ScopeType::Company,
        ?int $scopeDepartmentId = null,
        ?User $grantedBy = null,
    ): User {
        $user = User::findOrFail($userId);
        $role = Role::findOrFail($roleId);

        $this->ensureWithinReach($grantedBy, $user, $scopeType, $scopeDepartmentId);

        DB::transaction(function () use ($user, $role, $scopeType, $scopeDepartmentId): void {
            User::whereKey($user->getKey())->lockForUpdate()->first();

            UserRole::query()->firstOrCreate([
                'user_id'             => $user->getKey(),
                'role_id'             => $role->getKey(),
                'scope_type'          => $scopeType->value,
                'scope_department_id' => $scopeDepartmentId,
            ]);
        });

        PermissionCache::flush();

        $this->auditLogService->logAction(
            event: 'role_assigned',
            description: "Role '{$role->name}' assigned to user {$user->loginIdentity()} ({$this->describeScope($scopeType, $scopeDepartmentId)}).",
        );

        // `employee` is loaded alongside `roles` because UserResource resolves
        // the account's display name through it — a user row has no name of its
        // own. Returning only `roles` left the Resource to dereference
        // `$this->employee` during serialization, which lazy-loads inside the
        // response layer and breaks the Resource contract ("MUST NOT perform
        // queries or trigger lazy loads"). Verified: one extra SELECT against
        // `employees` per assign/unassign response.
        return $user->load(UserService::RENDER_RELATIONS);
    }

    /**
     * Revoke a role from a user (idempotent — revoking a grant the user does
     * not hold is a harmless no-op). Revoking access is security-relevant, so
     * it is audited just like assignment.
     *
     * ⚙️ WITH A SCOPE, ONLY THAT GRANT GOES; WITHOUT ONE, EVERY GRANT OF THE
     * ROLE GOES. The second is the pre-GAP-3 contract ("take this role away")
     * and stays exactly that — a caller revoking a role should not have to know
     * how many scopes it was granted over to be sure it is gone.
     *
     * The cache flush is what makes a revocation immediate rather than
     * effective within the hour (work order §6).
     *
     * `$revokedBy` bounds a revocation exactly as `$grantedBy` bounds a grant:
     * every grant that would go must lie within their reach, or nothing goes.
     * A section's administrator must not be able to strip a company-wide
     * grant, any more than hand one out.
     *
     * @throws GrantOutOfReachException
     */
    public function unassign(
        int $userId,
        int $roleId,
        ?ScopeType $scopeType = null,
        ?int $scopeDepartmentId = null,
        ?User $revokedBy = null,
    ): User {
        $user = User::findOrFail($userId);
        $role = Role::findOrFail($roleId);

        $grants = UserRole::query()
            ->where('user_id', $user->getKey())
            ->where('role_id', $role->getKey())
            ->when($scopeType !== null, fn ($query) => $query
                ->where('scope_type', $scopeType->value)
                ->when(
                    $scopeDepartmentId === null,
                    fn ($inner) => $inner->whereNull('scope_department_id'),
                    fn ($inner) => $inner->where('scope_department_id', $scopeDepartmentId),
                ));

        if ($revokedBy !== null) {
            foreach ((clone $grants)->get() as $grant) {
                // A grant whose stored type is undefined reaches nothing, so
                // anyone who may revoke at all may clear it away.
                if ($grant->scope_type !== null) {
                    $this->ensureWithinReach($revokedBy, $user, $grant->scope_type, $grant->scope_department_id === null ? null : (int) $grant->scope_department_id);
                }
            }
        }

        $grants->delete();

        PermissionCache::flush();

        $scope = $scopeType === null ? 'every scope' : $this->describeScope($scopeType, $scopeDepartmentId);

        $this->auditLogService->logAction(
            event: 'role_unassigned',
            description: "Role '{$role->name}' revoked from user {$user->loginIdentity()} ({$scope}).",
        );

        // `employee` is loaded alongside `roles` because UserResource resolves
        // the account's display name through it — a user row has no name of its
        // own. Returning only `roles` left the Resource to dereference
        // `$this->employee` during serialization, which lazy-loads inside the
        // response layer and breaks the Resource contract ("MUST NOT perform
        // queries or trigger lazy loads"). Verified: one extra SELECT against
        // `employees` per assign/unassign response.
        return $user->load(UserService::RENDER_RELATIONS);
    }

    /**
     * A role administrator may hand out — or take away — only what their own
     * `auth.roles.assign` grant reaches (GAP-3; the permission was
     * `auth.roles.update` until GAP-15 split role editing away from it).
     *
     * Without this, the scope on the assigner's grant bounded nothing: a
     * section's HR administrator could grant anyone, themselves included, a
     * company-wide role. So:
     *
     *   company-wide assigner (or super admin) → any grant;
     *   company grant  → company-wide assigners only;
     *   unit grant     → the unit must lie inside the assigner's reach, and
     *                    so then does everything beneath it;
     *   managed grant  → every unit the grantee manages TODAY must lie inside
     *                    it. A managed grant follows its holder around the
     *                    tree by design (decision 18); moving a manager is a
     *                    departments decision, made by whoever may make it —
     *                    and since GAP-15 only a company-wide grant may.
     *
     * ⚙️ A MANAGED GRANT TO SOMEONE WHO MANAGES NOTHING is refused to a
     * scoped assigner (2026-09-26). The rule above checks the units the
     * grantee manages today, and for a grantee who manages none there is
     * nothing to check — so the grant passed vacuously, and its reach was then
     * set entirely by whichever unit that person is appointed to next, however
     * far outside the assigner's own reach. Refused both ways, as every rule
     * here is: a scoped assigner can neither issue such a grant nor strip one.
     *
     * ⚠️ WHERE, NOT WHAT — GAP-16, OPEN. This checks where a grant applies,
     * never what the role carries. Inside their reach an assigner can hand out
     * any role, to anyone including themselves: a clerk holding only
     * `auth.roles.assign` over a unit can make themselves that unit's payroll.
     * PermissionReach caps it at the unit — company-only permissions do
     * nothing on a scoped grant — but within the unit it is an escalation. See
     * API_BUILD_STANDARD §12.
     *
     * @throws GrantOutOfReachException
     */
    private function ensureWithinReach(?User $actor, User $grantee, ScopeType $scopeType, ?int $scopeDepartmentId): void
    {
        if ($actor === null) {
            return;
        }

        $reach = $this->scopes->for($actor, self::GRANT_PERMISSION);

        if ($reach->isUnrestricted()) {
            return;
        }

        $units = match ($scopeType) {
            ScopeType::Company => [null],
            ScopeType::Unit    => [$scopeDepartmentId],
            ScopeType::Managed => $this->scopes->managedUnitIds($grantee),
        };

        if ($scopeType === ScopeType::Managed && $units === []) {
            throw new GrantOutOfReachException(
                'نطاق «الوحدات التي أديرها» لمستخدم لا يدير حالياً أي وحدة لا يمنحه ولا يسحبه إلا من يملك صلاحية الإسناد على مستوى الشركة كاملة.',
            );
        }

        foreach ($units as $unit) {
            if (! $reach->covers($unit)) {
                throw new GrantOutOfReachException;
            }
        }
    }

    /**
     * The scope as the audit trail should read it. The unit is named, not just
     * numbered, because the trail must stay readable after the tree changes.
     */
    private function describeScope(ScopeType $scopeType, ?int $scopeDepartmentId): string
    {
        if ($scopeDepartmentId === null) {
            return "scope: {$scopeType->name}";
        }

        $name = Department::withTrashed()->whereKey($scopeDepartmentId)->value('name');

        return "scope: {$scopeType->name} #{$scopeDepartmentId} {$name}";
    }
}
