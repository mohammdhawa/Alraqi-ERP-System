<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Exceptions\SystemRoleException;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Support\PermissionCache;
use App\Shared\Services\AuditLogService;
use Illuminate\Database\Eloquent\Collection;
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
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * All roles with their permissions, for admin listing.
     *
     * @return Collection<int, Role>
     */
    public function list(): Collection
    {
        return Role::query()->with('permissions')->orderBy('name')->get();
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
            throw new SystemRoleException();
        }
    }

    /**
     * Grant a role to a user (idempotent — no duplicate pivot rows).
     */
    public function assign(User $user, Role $role): User
    {
        $user->roles()->syncWithoutDetaching([$role->id]);

        PermissionCache::flush();

        $this->auditLogService->logAction(
            event: 'role_assigned',
            description: "Role '{$role->name}' assigned to user {$user->email}.",
        );

        return $user->load('roles');
    }

    /**
     * Revoke a role from a user (idempotent — detaching a role the user does
     * not hold is a harmless no-op). Revoking access is security-relevant, so
     * it is audited just like assignment.
     */
    public function unassign(User $user, Role $role): User
    {
        $user->roles()->detach($role->id);

        PermissionCache::flush();

        $this->auditLogService->logAction(
            event: 'role_unassigned',
            description: "Role '{$role->name}' revoked from user {$user->email}.",
        );

        return $user->load('roles');
    }
}
