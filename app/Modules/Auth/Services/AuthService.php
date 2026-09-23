<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Exceptions\AccountDisabledException;
use App\Modules\Auth\Exceptions\AuthenticationException;
use App\Modules\Auth\Exceptions\InvalidRefreshTokenException;
use App\Modules\Auth\Models\RefreshToken;
use App\Modules\Auth\Models\User;
use App\Shared\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * AuthService
 *
 * Encapsulates all authentication business logic. Controllers call this service;
 * they never touch models, hashing, or token generation directly.
 *
 * WHY a service layer:
 * - Controllers stay thin (receive request → call service → return response).
 * - Business logic is testable without HTTP (unit test the service directly).
 * - Multiple controllers or console commands can reuse the same logic.
 * - Changes to auth flow happen in one place.
 *
 * TOKEN ARCHITECTURE:
 *
 *   Access Token (Sanctum)         Refresh Token (custom)
 *   ─────────────────────          ──────────────────────
 *   Short-lived (15 min)           Long-lived (30 days)
 *   Sent on every API request      Sent only to /auth/refresh
 *   Stored by Sanctum              Hashed in refresh_tokens table
 *   Stateless validation           Database lookup on refresh
 *   Revoked on logout              Revoked + rotated on refresh
 *
 * FLOW:
 *   1. Login → returns access_token + refresh_token
 *   2. API calls use access_token in Authorization header
 *   3. When access_token expires (401), client calls /auth/refresh with refresh_token
 *   4. Server validates refresh_token, revokes it, issues new pair
 *   5. Logout revokes all tokens for the user
 *
 * SECURITY:
 *   - Refresh tokens are hashed with SHA-256 before storage.
 *   - Token rotation prevents replay attacks.
 *   - Using a revoked token triggers full session revocation (theft detection).
 *   - Database transactions ensure atomicity of rotate operations.
 */
class AuthService
{
    /**
     * The access-token lifetime is READ FROM CONFIG, never redeclared here.
     *
     * WHY (P1 — one fact, one place): this used to be a hardcoded
     * `ACCESS_TOKEN_EXPIRATION_MINUTES = 15` const, while `config/sanctum.php`
     * separately declared `'expiration' => env('SANCTUM_TOKEN_EXPIRATION', 15)`
     * and AuthController twice published `15 * 60` to clients. Four copies of
     * one number, and only the config one responded to the env var it exposes.
     *
     * The failure that made this worth fixing: set SANCTUM_TOKEN_EXPIRATION=5
     * and Sanctum's guard starts rejecting tokens at five minutes, while this
     * service still stamps expires_at at fifteen and the API still advertises
     * expires_in: 900. Every client schedules its refresh for minute 15 on a
     * token that died at minute 5 — a 401 storm that reads as a client bug.
     *
     * NULL is meaningful and is Laravel's own default: it means access tokens
     * do not expire on their own. It is carried through honestly rather than
     * coerced to a number — expiresAt stays null, and expires_in is reported as
     * null so a client does not schedule a refresh it never needs.
     */
    private function accessTokenLifetimeMinutes(): ?int
    {
        $minutes = config('sanctum.expiration');

        return $minutes === null ? null : (int) $minutes;
    }

    /**
     * The same lifetime as seconds, for the `expires_in` the API advertises.
     * Derived, never written down a second time.
     */
    private function accessTokenLifetimeSeconds(): ?int
    {
        $minutes = $this->accessTokenLifetimeMinutes();

        return $minutes === null ? null : $minutes * 60;
    }

    /**
     * Refresh token lifetime in days.
     * 30 days means users stay logged in for a month of inactivity.
     * Adjust based on your security posture. Financial ERPs may want 7 days.
     */
    private const REFRESH_TOKEN_EXPIRATION_DAYS = 30;

    /**
     * Sanctum token ability scopes.
     * All access tokens get these by default. Future: per-role scoping.
     */
    private const DEFAULT_TOKEN_ABILITIES = ['*'];

    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * Authenticate a user and issue a token pair.
     *
     * ⚙️ `$login` IS AN EMPLOYEE NUMBER OR AN EMAIL (account model, 2026-09-22).
     * It used to be an email and nothing else, which could not work once every
     * employee needed an account: most site staff have no email address.
     */
    public function login(string $login, string $password): array
    {
        $user = $this->resolveByLogin($login);

        if (! $user || ! Hash::check($password, $user->password)) {
            // SECURITY: Generic message prevents user enumeration.
            // Attacker cannot determine if the email exists.
            throw new AuthenticationException('بيانات الاعتماد غير صحيحة.');
        }

        if (! $user->isActive()) {
            throw new AccountDisabledException;
        }

        return DB::transaction(function () use ($user): array {
            $accessToken  = $this->createAccessToken($user);
            $refreshToken = $this->createRefreshToken($user);

            // Stamp the last successful login. saveQuietly() bypasses model events
            // so it does NOT emit a HasAuditLog "updated" row on every sign-in —
            // the user_logged_in action log below already records the event.
            $user->last_login_at = now();
            $user->saveQuietly();

            $this->auditLogService->logAction(
                event: 'user_logged_in',
                description: "User {$user->loginIdentity()} logged in.",
            );

            return [
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
                // The lifetime is a business fact about the token this service
                // just minted, so this service reports it. The controller used
                // to write `15 * 60` inline, which is how it drifted.
                'expires_in'    => $this->accessTokenLifetimeSeconds(),
                'user'          => $user,
            ];
        });
    }

    /**
     * Rotate refresh token: revoke old, issue new pair.
     *
     * This is the core of the refresh flow. Every refresh token is single-use.
     * Using it a second time (replay) means it was stolen.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int|null}
     *
     * @throws InvalidRefreshTokenException
     */
    public function refreshTokens(string $plainRefreshToken): array
    {
        $tokenHash = $this->hashToken($plainRefreshToken);

        // SECURITY: When token reuse (replay) is detected we must revoke the
        // user's entire session and record a theft-detection audit event. Those
        // side effects MUST survive the InvalidRefreshTokenException thrown
        // below — if they ran inside the rotation transaction, the throw would
        // roll them back and silently undo the security response. So we only
        // capture the offending user id here and act on it once the transaction
        // has unwound (see the catch block).
        $replayUserId = null;

        try {
            return DB::transaction(function () use ($tokenHash, &$replayUserId): array {
                // Lock the row to prevent race conditions during rotation.
                // Two simultaneous refresh requests with the same token must not
                // both succeed — one must fail.
                $refreshToken = RefreshToken::where('token_hash', $tokenHash)
                    ->lockForUpdate()
                    ->first();

                if (! $refreshToken) {
                    throw new InvalidRefreshTokenException('رمز التحديث غير صالح.');
                }

                // SECURITY: A revoked token being used again indicates token theft.
                // The actual revocation happens after this transaction (see above).
                if ($refreshToken->revoked) {
                    $replayUserId = $refreshToken->user_id;

                    throw new InvalidRefreshTokenException(
                        'تم إبطال هذا الرمز. تم إنهاء جميع الجلسات حفاظًا على الأمان.'
                    );
                }

                if ($refreshToken->expires_at->isPast()) {
                    $refreshToken->update(['revoked' => true]);
                    throw new InvalidRefreshTokenException('انتهت صلاحية رمز التحديث.');
                }

                $user = $refreshToken->user;

                if (! $user || ! $user->isActive()) {
                    throw new InvalidRefreshTokenException('الحساب غير مُفعّل.');
                }

                // Revoke the current refresh token (single-use)
                $refreshToken->update(['revoked' => true]);

                // Issue new pair
                $newAccessToken  = $this->createAccessToken($user);
                $newRefreshToken = $this->createRefreshToken($user);

                $this->auditLogService->logAction(
                    event: 'tokens_refreshed',
                    description: "Tokens rotated for user {$user->loginIdentity()}.",
                );

                return [
                    'access_token'  => $newAccessToken,
                    'refresh_token' => $newRefreshToken,
                    'expires_in'    => $this->accessTokenLifetimeSeconds(),
                ];
            });
        } catch (InvalidRefreshTokenException $e) {
            // Replay detected: revoke ALL of the user's tokens as a precaution.
            // Runs outside the rolled-back transaction so it actually persists.
            if ($replayUserId !== null) {
                $this->revokeAllUserTokens($replayUserId);

                $this->auditLogService->logAction(
                    event: 'refresh_token_replay_detected',
                    description: "Possible token theft for user ID {$replayUserId}. All tokens revoked.",
                );
            }

            throw $e;
        }
    }

    /**
     * Logout: revoke all tokens for the authenticated user.
     *
     * Revokes both Sanctum access tokens and all refresh tokens.
     * After this, the user must log in again on all devices.
     */
    public function logout(User $user): void
    {
        DB::transaction(function () use ($user): void {
            // Revoke all Sanctum access tokens
            $user->tokens()->delete();

            // Revoke all refresh tokens
            $user->refreshTokens()
                ->where('revoked', false)
                ->update(['revoked' => true]);

            $this->auditLogService->logAction(
                event: 'user_logged_out',
                description: "User {$user->loginIdentity()} logged out. All tokens revoked.",
            );
        });
    }

    /**
     * Replace the caller's own password.
     *
     * SELF-SCOPED (C7): the $user is always the authenticated caller, resolved
     * by the controller from the request. Nothing here takes an id, so there is
     * no shape in which this service method can change somebody else's
     * credential — an admin reset goes through UserService, which is a
     * different action with a different permission.
     *
     * ⚠️ EVERY OTHER SESSION IS REVOKED. The case this exists for is the
     * forced first change, where the current password was issued by HR and is
     * therefore a secret more than one person holds. If someone else signed in
     * with it first, leaving their tokens alive would mean the change locked
     * nobody out — which is the entire point of the change. The caller's own
     * new pair is minted after the revocation, so they stay signed in and
     * everyone else does not.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int|null}
     *
     * @throws AuthenticationException when the current password does not match
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword): array
    {
        if (! Hash::check($currentPassword, $user->password)) {
            // Deliberately NOT the generic credentials message: the caller is
            // already authenticated, so there is no enumeration to protect
            // against here, and "wrong current password" is the one thing they
            // need to know to fix it.
            throw new AuthenticationException('كلمة المرور الحالية غير صحيحة.');
        }

        return DB::transaction(function () use ($user, $newPassword): array {
            $user->tokens()->delete();
            $user->refreshTokens()
                ->where('revoked', false)
                ->update(['revoked' => true]);

            // Saved through the model, not quietly: HasAuditLog records the
            // change, and AuditLogService::sanitize() already redacts
            // `password`, so the trail gets "this account changed its password"
            // without the value. `password_changed_at` answers "when was this
            // credential last rotated" — `updated_at` cannot, because it moves
            // for any change at all.
            $user->password             = $newPassword;
            $user->must_change_password = false;
            $user->password_changed_at  = now();
            $user->save();

            $this->auditLogService->logAction(
                event: 'password_changed',
                description: "User {$user->loginIdentity()} changed their password. Other sessions revoked.",
            );

            return [
                'access_token'  => $this->createAccessToken($user),
                'refresh_token' => $this->createRefreshToken($user),
                'expires_in'    => $this->accessTokenLifetimeSeconds(),
            ];
        });
    }

    /**
     * Revoke all tokens for a user (admin action or theft detection).
     */
    public function revokeAllUserTokens(int $userId): void
    {
        DB::transaction(function () use ($userId): void {
            $user = User::findOrFail($userId);

            $user->tokens()->delete();

            $user->refreshTokens()
                ->where('revoked', false)
                ->update(['revoked' => true]);

            $this->auditLogService->logAction(
                event: 'all_tokens_revoked',
                description: "All tokens revoked for user ID {$userId}.",
            );
        });
    }

    /**
     * Create a short-lived Sanctum access token.
     *
     * Sanctum stores the hashed token in personal_access_tokens.
     * Returns the plaintext token (only available at creation time).
     */
    /**
     * Find the account a login identifier belongs to.
     *
     * TWO IDENTIFIERS, ONE FIELD. The employee number is how staff sign in; the
     * email is how an account with no employee does — the technical department
     * and `super_admin`, which have no `employees` row and therefore no number.
     * The order matters only for cost, not for correctness: the two spaces
     * cannot collide, because an employee number is generated or hand-typed and
     * an email contains an `@`.
     *
     * ⚠️ THE CLAUSE IS GROUPED. An ungrouped `orWhere` at the top level of a
     * query escapes any condition added beside it later — the same defect this
     * codebase already fixed once in employee search. Here it would be worse
     * than a wrong list: a future `->where('is_active', true)` would bind to
     * the first branch only, and a disabled account would authenticate through
     * the second.
     *
     * The employee is matched through the relation rather than a join on a
     * copied column, which is why no `employee_number` lives on `users`.
     * `whereHas` excludes soft-deleted employees, so a deleted person's account
     * stops resolving by number — deliberate, and the reason the email branch
     * is not the same query.
     */
    private function resolveByLogin(string $login): ?User
    {
        return User::query()
            ->where(function ($query) use ($login): void {
                $query
                    ->whereHas('employee', fn ($employee) => $employee->where('employee_number', $login))
                    ->orWhere('email', $login);
            })
            ->with('employee')
            ->first();
    }

    private function createAccessToken(User $user): string
    {
        // Delete existing access tokens to prevent accumulation.
        // In a multi-device scenario, you might keep them and rely on expiry.
        // For an ERP with controlled access, cleanup is cleaner.
        $user->tokens()->delete();

        $minutes = $this->accessTokenLifetimeMinutes();

        $token = $user->createToken(
            name: 'access_token',
            abilities: self::DEFAULT_TOKEN_ABILITIES,
            // Null means "no per-token expiry" — the same thing config/sanctum's
            // null means — rather than a silently different lifetime here.
            expiresAt: $minutes === null ? null : now()->addMinutes($minutes),
        );

        return $token->plainTextToken;
    }

    /**
     * Create a long-lived refresh token.
     *
     * Generates a cryptographically random token, stores only its hash.
     * Returns the plaintext token (only available at creation time).
     */
    private function createRefreshToken(User $user): string
    {
        $plainToken = Str::random(64);

        RefreshToken::create([
            'user_id'    => $user->id,
            'token_hash' => $this->hashToken($plainToken),
            'expires_at' => now()->addDays(self::REFRESH_TOKEN_EXPIRATION_DAYS),
            'revoked'    => false,
        ]);

        return $plainToken;
    }

    /**
     * Hash a token using SHA-256.
     *
     * WHY SHA-256 and not bcrypt:
     * - Refresh tokens are 64 random characters (high entropy).
     * - Bcrypt's slow hashing is designed for LOW-entropy passwords.
     * - SHA-256 is sufficient for high-entropy secrets and is much faster.
     * - This matches Sanctum's own approach for access tokens.
     * - At 64 random chars, brute force is infeasible regardless of hash speed.
     */
    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
