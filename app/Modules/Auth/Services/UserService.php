<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Exceptions\CannotDeleteOwnAccountException;
use App\Modules\Auth\Models\User;
use App\Shared\Services\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
     * A page of users with their roles and linked employee (the source of the
     * display name). The employee is eager-loaded so the resource resolves each
     * user's name without an N+1.
     *
     * WAS UNPAGINATED (C27, GAP-11). This returned every row through a bare
     * ->get(), so the endpoint carried no `meta` and a client could not write
     * one pager against the API. `users` is a table that grows: Batch 4 imports
     * 242 active employees, and every one of them who needs a login lands here.
     *
     * ORDERED BY email THEN id (C29). Email is unique today, so the tie-break
     * looks redundant — it is not. It costs nothing, it survives the column
     * becoming non-unique, and it makes the rule visibly uniform across every
     * list query in the system rather than something each author re-derives.
     *
     * $perPage arrives as an int from the controller, already clamped. This
     * signature never reads the query string, so it stays callable from a
     * console command or a job (C1).
     *
     * @return LengthAwarePaginator<User>
     */
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return User::query()
            ->with(['roles', 'employee'])
            ->orderBy('email')
            ->orderBy('id')
            ->paginate($perPage);
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
                'email'       => $data['email'] ?? null,
                'password'    => $data['password'],
                'is_active'   => $data['is_active'] ?? true,
                // ⚙️ ALWAYS TRUE ON CREATION (account model, 2026-09-22). An
                // account created here is created BY SOMEBODY ELSE, so its
                // password is a secret two people hold. That is true of the 242
                // accounts the importer will make and equally true of the one an
                // admin makes next month — so the rule lives here, not in the
                // importer, or it would apply to the first batch only.
                'must_change_password' => true,
            ]);

            $this->auditLogService->logAction(
                event: 'user_created',
                description: "User '{$user->loginIdentity()}' created.",
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
                // An admin reset is somebody else choosing your credential, so
                // it carries the same obligation as the first one. Without this
                // line the forced change would apply to new accounts and not to
                // resets — and a reset is the more common case, because it is
                // what happens every time an employee forgets.
                $user->must_change_password = true;
            }
            if (array_key_exists('is_active', $data)) {
                $user->is_active = $data['is_active'];
            }
            $user->save();

            $this->auditLogService->logAction(
                event: 'user_updated',
                description: "User '{$user->loginIdentity()}' updated.",
            );

            return $user->load(['roles', 'employee']);
        });
    }

    /**
     * Delete a user.
     *
     * SELF-DELETION IS REFUSED HERE, not in the controller (C4, C10). Deleting
     * the account you are signed in as locks you out mid-session with no way to
     * undo it. The rule used to sit in UserController::destroy, where it
     * protected exactly one path and left this method — reachable from a console
     * command, a job, or any future screen — wide open.
     *
     * $actingUserId is REQUIRED and has no default (P3): a caller must state who
     * is performing the deletion, or explicitly pass null. Null means "no
     * session" — a console command or a queued job, where there is no `self` for
     * the rule to be about — and is not a way to opt out of the check.
     *
     * @throws CannotDeleteOwnAccountException
     */
    public function delete(User $user, ?int $actingUserId): void
    {
        if ($actingUserId !== null && (int) $user->getKey() === $actingUserId) {
            throw new CannotDeleteOwnAccountException;
        }

        $email = $user->email;

        DB::transaction(function () use ($user): void {
            // The delete is now SOFT, so the FK cascades that used to clear
            // these no longer fire. Revoke explicitly rather than relying on a
            // trashed user resolving to null in the guard: that is true, but it
            // is a second-order consequence of a global scope, and "the deleted
            // account's tokens are dead" should be a fact this method asserts.
            $user->tokens()->delete();
            $user->refreshTokens()->delete();

            $user->delete();
        });

        $this->auditLogService->logAction(
            event: 'user_deleted',
            description: "User '{$email}' deleted.",
        );
    }
}
