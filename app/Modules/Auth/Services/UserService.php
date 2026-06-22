<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\User;
use App\Shared\Services\AuditLogService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * UserService
 *
 * Business logic for administering user accounts (list/create/update/delete).
 * Creating, modifying, or removing an account is a security-relevant action, so
 * each write is recorded in the audit log (§16.14).
 *
 * Role grants are intentionally out of scope here — they live in RoleService
 * (assign/unassign) so RBAC changes have a single source of truth.
 */
class UserService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * All users with their roles, for admin listing.
     *
     * @return Collection<int, User>
     */
    public function list(): Collection
    {
        return User::query()->with('roles')->orderBy('name')->get();
    }

    /**
     * Create a user account.
     *
     * Wrapped in a transaction for consistency with the rest of the module and
     * to keep room for follow-on writes (e.g. welcome notification) without
     * partial state. The `password` cast hashes the value on assignment.
     *
     * @param  array{name: string, email: string, password: string, is_active?: bool}  $data
     */
    public function create(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = User::create([
                'name'      => $data['name'],
                'email'     => $data['email'],
                'password'  => $data['password'],
                'is_active' => $data['is_active'] ?? true,
            ]);

            $this->auditLogService->logAction(
                event: 'user_created',
                description: "User '{$user->email}' created.",
            );

            return $user->load('roles');
        });
    }

    /**
     * Update a user account. Only keys present in $data are touched, so partial
     * updates are safe. An empty/null password is ignored — the field can be
     * sent without forcing a password change.
     *
     * @param  array{name?: string, email?: string, password?: string|null, is_active?: bool}  $data
     */
    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data): User {
            if (array_key_exists('name', $data)) {
                $user->name = $data['name'];
            }
            if (array_key_exists('email', $data)) {
                $user->email = $data['email'];
            }
            if (array_key_exists('password', $data) && ! empty($data['password'])) {
                $user->password = $data['password'];
            }
            if (array_key_exists('is_active', $data)) {
                $user->is_active = $data['is_active'];
            }
            $user->save();

            $this->auditLogService->logAction(
                event: 'user_updated',
                description: "User '{$user->email}' updated.",
            );

            return $user->load('roles');
        });
    }

    /**
     * Delete a user. The user_roles and refresh_tokens rows cascade.
     */
    public function delete(User $user): void
    {
        $email = $user->email;

        $user->delete();

        $this->auditLogService->logAction(
            event: 'user_deleted',
            description: "User '{$email}' deleted.",
        );
    }
}
