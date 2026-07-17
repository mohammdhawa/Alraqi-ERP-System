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
     * All users with their roles and linked employee (the source of the display
     * name), for admin listing. Ordered by email since the account itself has no
     * name column. The employee is eager-loaded so the resource resolves each
     * user's name without an N+1.
     *
     * @return Collection<int, User>
     */
    public function list(): Collection
    {
        return User::query()->with(['roles', 'employee'])->orderBy('email')->get();
    }

    /**
     * Create a user account, linked to an employee (its display name comes from
     * there — there is no user.name).
     *
     * Wrapped in a transaction for consistency with the rest of the module and
     * to keep room for follow-on writes (e.g. welcome notification) without
     * partial state. The `password` cast hashes the value on assignment.
     *
     * @param  array{employee_id: int, email: string, password: string, is_active?: bool}  $data
     */
    public function create(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = User::create([
                'employee_id' => $data['employee_id'],
                'email'       => $data['email'],
                'password'    => $data['password'],
                'is_active'   => $data['is_active'] ?? true,
            ]);

            $this->auditLogService->logAction(
                event: 'user_created',
                description: "User '{$user->email}' created.",
            );

            return $user->load(['roles', 'employee']);
        });
    }

    /**
     * Update a user account. Only keys present in $data are touched, so partial
     * updates are safe. An empty/null password is ignored — the field can be
     * sent without forcing a password change. There is no `name` to update; a
     * user's name is the linked employee's, so employee_id is what re-labels it.
     *
     * @param  array{employee_id?: int, email?: string, password?: string|null, is_active?: bool}  $data
     */
    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data): User {
            if (array_key_exists('employee_id', $data)) {
                $user->employee_id = $data['employee_id'];
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

            return $user->load(['roles', 'employee']);
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
