<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Auth\Exceptions\GrantOutOfReachException;
use App\Modules\Auth\Exceptions\PermissionNotHeldException;
use App\Modules\Auth\Exceptions\SystemRoleException;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Models\UserRole;
use App\Modules\Auth\Support\PermissionCache;
use App\Modules\Auth\Support\PermissionReach;
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
     * `$actingUser` may put into the role only permissions they hold
     * company-wide (GAP-16, see ensureHoldsCompanyWide()). Null means the
     * system itself — a seeder or a console command.
     *
     * @param  array{name: string, label?: string|null, description?: string|null, permissions?: array<int, string>}  $data
     *
     * @throws PermissionNotHeldException
     */
    public function create(array $data, ?User $actingUser): Role
    {
        $this->ensureHoldsCompanyWide(
            $actingUser,
            $data['permissions'] ?? [],
            'لا يُنشأ دور يحمل صلاحية لا تملكها أنت على مستوى «الشركة كاملة».',
        );

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
     * ⚙️ EVERY PERMISSION ADDED OR REMOVED must be one `$actingUser` holds
     * company-wide (GAP-16). Editing a role changes what every holder of it
     * may do, the editor included — so without this, an administrator
     * lacking `hr.employees.viewSalary` could add it to a role they hold and
     * have it on the next request, no grant needed. Removing is bounded too,
     * as revoking is: nobody takes away what they could not have given.
     *
     * @param  array{name?: string, label?: string|null, description?: string|null, permissions?: array<int, string>}  $data
     *
     * @throws SystemRoleException if the role is a system role.
     * @throws PermissionNotHeldException
     */
    public function update(Role $role, array $data, ?User $actingUser): Role
    {
        $this->guardNotSystem($role);

        if (array_key_exists('permissions', $data)) {
            $current = $role->permissions()->pluck('name')->all();
            $next = Permission::whereIn('name', $data['permissions'])->pluck('name')->all();

            $this->ensureHoldsCompanyWide(
                $actingUser,
                [...array_diff($next, $current), ...array_diff($current, $next)],
                'لا تُضاف صلاحية إلى دور ولا تُزال منه ما لم تكن تملكها أنت على مستوى «الشركة كاملة»، لأن تعديل الدور يغيّر ما يملكه كل من أُسند إليه.',
            );
        }

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
     * Deleting a role revokes it from every holder, so `$actingUser` must hold
     * everything it carries, company-wide (GAP-16).
     *
     * @throws SystemRoleException if the role is a system role.
     * @throws PermissionNotHeldException
     */
    public function delete(Role $role, ?User $actingUser): void
    {
        $this->guardNotSystem($role);

        $this->ensureHoldsCompanyWide(
            $actingUser,
            $role->permissions()->pluck('name')->all(),
            'لا يُحذف دور يحمل صلاحية لا تملكها أنت على مستوى «الشركة كاملة»، لأن حذفه يسحبها من كل من أُسند إليه.',
        );

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
     * ensureMayGrant(). Null means the system itself (a seeder, a console
     * command), which answers to no grant. Every HTTP caller passes one.
     *
     * @throws GrantOutOfReachException when the grant reaches further than
     *                                  `$grantedBy` does, or is super_admin.
     * @throws PermissionNotHeldException when it confers a permission
     *                                    `$grantedBy` does not hold there.
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

        $this->ensureMayGrant($grantedBy, $user, $role, $scopeType, $scopeDepartmentId);

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
     * grant, any more than hand one out — nor strip a role carrying
     * permissions they could not have granted (GAP-16).
     *
     * @throws GrantOutOfReachException
     * @throws PermissionNotHeldException
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
                    $this->ensureMayGrant($revokedBy, $user, $role, $grant->scope_type, $grant->scope_department_id === null ? null : (int) $grant->scope_department_id);
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
     * May `$actor` hand out — or take away — this role over this scope?
     * Three rules, in this order. A super admin passes all three; null (the
     * system itself) is never asked.
     *
     * 1. super_admin IS GRANTED AND REVOKED BY A SUPER ADMIN ONLY (GAP-16,
     *    decision 24). Rule 3 cannot see it: the role carries no permission
     *    rows — its power is the Gate bypass — so a subset check passes it
     *    vacuously, and any company-wide assigner could crown themselves or
     *    depose every real administrator.
     *
     * 2. WHERE — the grant lies within the actor's own `auth.roles.assign`
     *    reach. See the rules below.
     *
     * 3. WHAT — THE SUBSET RULE (GAP-16, decision 24). Every permission the
     *    grant would confer, the actor must hold over the same units: what a
     *    company grant confers is the role's whole list; what a narrower one
     *    confers is what PermissionReach lets it — so the actor is measured
     *    against exactly what the grantee would gain or lose, no more. The
     *    actor's own side is their EFFECTIVE set, through AccessScope, which
     *    already applies PermissionReach to them. It binds company-wide
     *    assigners too — the project owner's call, 2026-09-28: an HR
     *    administrator who cannot see pay cannot hand out a payroll role,
     *    to themselves or to anyone.
     *
     * The WHERE rules (GAP-3):
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
     * A latent managed grant is held to the company for rule 3 as well: the
     * units it will cover are unknown, so the actor must hold what it confers
     * everywhere.
     *
     * Before 2026-09-28 only rule 2 existed, and a clerk holding
     * `auth.roles.assign` over a unit could make themselves that unit's
     * payroll (GAP-16).
     *
     * @throws GrantOutOfReachException
     * @throws PermissionNotHeldException
     */
    private function ensureMayGrant(?User $actor, User $grantee, Role $role, ScopeType $scopeType, ?int $scopeDepartmentId): void
    {
        if ($actor === null || $actor->isSuperAdmin()) {
            return;
        }

        if ($role->name === User::SUPER_ADMIN_ROLE) {
            throw new GrantOutOfReachException('دور مدير النظام لا يمنحه ولا يسحبه إلا مدير نظام.');
        }

        // The units the grant covers; [null] stands for the whole company,
        // which RowScope::covers() answers true for only when unrestricted.
        $units = match ($scopeType) {
            ScopeType::Company => [null],
            ScopeType::Unit    => [$scopeDepartmentId],
            ScopeType::Managed => $this->scopes->managedUnitIds($grantee),
        };

        $reach = $this->scopes->for($actor, self::GRANT_PERMISSION);

        if ($units === []) {
            if (! $reach->isUnrestricted()) {
                throw new GrantOutOfReachException(
                    'نطاق «الوحدات التي أديرها» لمستخدم لا يدير حالياً أي وحدة لا يمنحه ولا يسحبه إلا من يملك صلاحية الإسناد على مستوى الشركة كاملة.',
                );
            }

            $units = [null];
        }

        foreach ($units as $unit) {
            if (! $reach->covers($unit)) {
                throw new GrantOutOfReachException;
            }
        }

        $permissions = $role->permissions()->pluck('name')->all();
        $conferred = $scopeType === ScopeType::Company
            ? $permissions
            : PermissionReach::effectiveForScopedGrant($permissions);

        $missing = array_values(array_filter(
            $conferred,
            fn (string $permission): bool => ! $this->holdsOver($actor, $permission, $units),
        ));

        if ($missing !== []) {
            throw new PermissionNotHeldException(
                $this->labelsFor($missing),
                'لا يُمنح دور ولا يُسحب إلا ممن يملك كل ما يمنحه هذا الدور على النطاق نفسه.',
            );
        }
    }

    /**
     * Role DEFINITIONS are company-wide objects, so whoever edits one must hold
     * every permission they add, remove or delete across the whole company
     * (GAP-16, decision 24). `auth.roles.create/update/delete` are
     * company-only already (GAP-15), so the editor is company-wide; this
     * bounds what they may put in, not where.
     *
     * @param  array<int, string>  $permissions
     *
     * @throws PermissionNotHeldException
     */
    private function ensureHoldsCompanyWide(?User $actor, array $permissions, string $lead): void
    {
        if ($actor === null || $actor->isSuperAdmin()) {
            return;
        }

        $missing = array_values(array_filter(
            array_unique($permissions),
            fn (string $permission): bool => ! $this->holdsOver($actor, $permission, [null]),
        ));

        if ($missing !== []) {
            throw new PermissionNotHeldException($this->labelsFor($missing), $lead);
        }
    }

    /**
     * Whether the actor holds the permission over every one of the units —
     * null standing for the whole company.
     *
     * ⚙️ A CATALOGUE READ HELD AT ALL IS HELD EVERYWHERE. `departments.view`,
     * `hr.job_titles.view` and `auth.roles.view` read the whole catalogue from
     * any grant (decision 23), so what the actor has is the same whichever
     * grant it came through. Measured by the grant's scope, a unit-scoped
     * holder would be refused handing out a company-wide read they already
     * exercise company-wide.
     *
     * @param  array<int, int|null>  $units
     */
    private function holdsOver(User $actor, string $permission, array $units): bool
    {
        if (in_array($permission, PermissionReach::CATALOGUE_READS, true)) {
            return $actor->hasPermission($permission);
        }

        $scope = $this->scopes->for($actor, $permission);

        foreach ($units as $unit) {
            if (! $scope->covers($unit)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Permission names as an administrator reads them — the catalogue's
     * Arabic label, the name itself where a row has none.
     *
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    private function labelsFor(array $names): array
    {
        $labels = Permission::whereIn('name', $names)->pluck('label', 'name');

        return array_map(static fn (string $name): string => $labels[$name] ?? $name, $names);
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
