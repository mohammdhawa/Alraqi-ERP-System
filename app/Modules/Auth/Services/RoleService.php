<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Shared\Services\AuditLogService;
use Illuminate\Database\Eloquent\Collection;

/**
 * RoleService
 *
 * Business logic for reading roles and assigning them to users. Granting access
 * is a security-relevant action, so each assignment is recorded in the audit
 * log (§16.14).
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
     * Grant a role to a user (idempotent — no duplicate pivot rows).
     */
    public function assign(User $user, Role $role): User
    {
        $user->roles()->syncWithoutDetaching([$role->id]);

        $this->auditLogService->logAction(
            event: 'role_assigned',
            description: "Role '{$role->name}' assigned to user {$user->email}.",
        );

        return $user->load('roles');
    }
}
