<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Enums\DevicePlatform;
use App\Modules\Auth\Exceptions\AccountDisabledException;
use App\Modules\Auth\Exceptions\AuthenticationException;
use App\Modules\Auth\Exceptions\InvalidRefreshTokenException;
use App\Modules\Auth\Models\RefreshToken;
use App\Modules\Auth\Models\User;
use App\Shared\Services\AuditLogService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

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
 *   5. Logout ends the caller's session; logout-all ends every session
 *
 * SESSIONS (2026-10-10, work order §4.3): one per signed-in device. Login
 * mints a `session_id`, every rotation copies it forward with the device it
 * was signed in from, and the access token is named after it — so a phone and
 * the dashboard signed in as one person no longer evict each other's access
 * token, and ending one session ends exactly one device.
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
    public function login(
        string $login,
        string $password,
        ?string $deviceName = null,
        ?DevicePlatform $platform = null,
    ): array {
        $user = $this->resolveByLogin($login);

        if (! $user || ! Hash::check($password, $user->password)) {
            // SECURITY: Generic message prevents user enumeration.
            // Attacker cannot determine if the email exists.
            throw new AuthenticationException('بيانات الاعتماد غير صحيحة.');
        }

        if (! $user->isActive()) {
            throw new AccountDisabledException;
        }

        return DB::transaction(function () use ($user, $deviceName, $platform): array {
            $session = [
                'session_id'   => (string) Str::uuid(),
                'device_name'  => $deviceName,
                'platform'     => $platform,
                'signed_in_at' => now(),
            ];

            $accessToken  = $this->createAccessToken($user, $session['session_id']);
            $refreshToken = $this->createRefreshToken($user, $session);

            // Stamp the last successful login. saveQuietly() bypasses model events
            // so it does NOT emit a HasAuditLog "updated" row on every sign-in —
            // the user_logged_in action log below already records the event.
            $user->last_login_at = now();
            $user->saveQuietly();

            // Unauthenticated by definition, so the account is passed (GAP-18).
            $this->auditLogService->logAction(
                event: 'user_logged_in',
                description: "User {$user->loginIdentity()} logged in.",
                fallbackUserId: $user->getKey(),
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

                // Issue the new pair IN THE SAME SESSION: a refresh continues
                // the device's session, it does not start another. A row from
                // before sessions existed has none, and starts one here.
                $session = $this->sessionOf($refreshToken);

                $newAccessToken  = $this->createAccessToken($user, $session['session_id']);
                $newRefreshToken = $this->createRefreshToken($user, $session);

                $this->auditLogService->logAction(
                    event: 'tokens_refreshed',
                    description: "Tokens rotated for user {$user->loginIdentity()}.",
                    fallbackUserId: $user->getKey(),
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

                // Attributed to the account whose credential was presented —
                // the request acted as it, whoever sent it. Unattributed, the
                // one event an investigation starts from could not be found
                // by filtering on the victim (GAP-18).
                $this->auditLogService->logAction(
                    event: 'refresh_token_replay_detected',
                    description: "Possible token theft for user ID {$replayUserId}. All tokens revoked.",
                    fallbackUserId: $replayUserId,
                );
            }

            throw $e;
        }
    }

    /**
     * Logout: end the caller's own session, and only it.
     *
     * ⚙️ CHANGED 2026-10-10. This used to revoke every token the user held, so
     * signing out of the phone signed the dashboard out too. Ending everything
     * is logoutEverywhere() now — what the legacy app's "log out of all
     * devices" maps onto.
     *
     * A request authenticated without a session token — Sanctum::actingAs()
     * in tests — has no session to end; the sign-out is still recorded.
     */
    public function logout(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $sessionId = $this->currentSessionId($user);

            if ($sessionId !== null) {
                $this->endSession($user, $sessionId);
            }

            $this->auditLogService->logAction(
                event: 'user_logged_out',
                description: "User {$user->loginIdentity()} logged out of one session.",
            );
        });
    }

    /**
     * End every session the user has, on every device.
     */
    public function logoutEverywhere(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();

            // Deleted, not marked revoked — see endSession().
            $user->refreshTokens()->delete();
            $user->deviceTokens()->delete();

            $this->auditLogService->logAction(
                event: 'user_logged_out_everywhere',
                description: "User {$user->loginIdentity()} logged out of every session.",
            );
        });
    }

    /**
     * The caller's live sessions, newest sign-in first.
     *
     * A session is live while its head — the one row of it neither revoked
     * nor expired — exists. "Last used" is the session's access token's
     * `last_used_at`, which Sanctum stamps on every request, falling back to
     * the head's own creation when that token has not been used yet.
     *
     * @return array<int, array{id: string, device_name: ?string, platform: ?string, signed_in_at: ?string, last_used_at: ?string, is_current: bool}>
     */
    public function sessions(User $user): array
    {
        $heads = $this->liveHeads($user)->get();

        $lastUsed = $user->tokens()
            ->whereIn('name', $heads->map(fn (RefreshToken $head): string => self::accessTokenName($head->session_id)))
            ->pluck('last_used_at', 'name');

        $current = $this->currentSessionId($user);

        return $heads
            ->sortByDesc(fn (RefreshToken $head) => $head->signed_in_at)
            ->map(function (RefreshToken $head) use ($lastUsed, $current): array {
                $used = $lastUsed[self::accessTokenName($head->session_id)] ?? null;

                return [
                    'id'           => $head->session_id,
                    'device_name'  => $head->device_name,
                    'platform'     => $head->platform?->value,
                    'signed_in_at' => $head->signed_in_at?->toIso8601String(),
                    'last_used_at' => ($used !== null ? Carbon::parse($used) : $head->created_at)?->toIso8601String(),
                    'is_current'   => $head->session_id === $current,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * End one of the caller's sessions — another device, or this one.
     *
     * Looked up among the CALLER's live sessions only, so another account's
     * session id, or one already ended, is a 404 rather than a 403: whether
     * someone else's session exists is not the caller's business.
     *
     * @throws ModelNotFoundException
     */
    public function revokeSession(User $user, string $sessionId): void
    {
        $this->liveHeads($user)->where('session_id', $sessionId)->firstOrFail();

        DB::transaction(function () use ($user, $sessionId): void {
            $this->endSession($user, $sessionId);

            $this->auditLogService->logAction(
                event: 'session_revoked',
                description: "User {$user->loginIdentity()} ended a session.",
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

        // The caller's own session survives the change, with the device it was
        // signed in from; read it before everything is revoked below.
        $current = $this->currentSessionId($user);
        $head = $current === null ? null : $this->liveHeads($user)->where('session_id', $current)->first();

        return DB::transaction(function () use ($user, $newPassword, $head): array {
            // Deleted, not marked revoked — see endSession(). Revoked, the
            // other devices' next routine refresh read as a replay and ended
            // the session minted below as well, signing the person who just
            // changed their password out of the device they changed it on.
            $user->tokens()->delete();
            $user->refreshTokens()->delete();

            // The other devices stop receiving pushes with their sessions; the
            // caller's device keeps its token, because its session continues.
            $user->deviceTokens()
                ->where(fn ($query) => $query->whereNull('session_id')->orWhere('session_id', '!=', $head?->session_id))
                ->delete();

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

            $session = $this->sessionOf($head);

            return [
                'access_token'  => $this->createAccessToken($user, $session['session_id']),
                'refresh_token' => $this->createRefreshToken($user, $session),
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

            $user->deviceTokens()->delete();

            // From theft detection nobody is authenticated and the account is
            // the subject; from an administrator, the administrator is the
            // actor and the context wins (GAP-18).
            $this->auditLogService->logAction(
                event: 'all_tokens_revoked',
                description: "All tokens revoked for user ID {$userId}.",
                fallbackUserId: $userId,
            );
        });
    }

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

    /**
     * The Sanctum token name that ties an access token to its session.
     */
    public static function accessTokenName(string $sessionId): string
    {
        return 'session:'.$sessionId;
    }

    /**
     * The session the current request's access token belongs to, or null for
     * a request authenticated some other way (a transient token in tests).
     */
    public function currentSessionId(User $user): ?string
    {
        $token = $user->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || ! str_starts_with((string) $token->name, 'session:')) {
            return null;
        }

        return substr((string) $token->name, strlen('session:'));
    }

    /**
     * The heads of the user's live sessions: one row per session, neither
     * revoked nor expired.
     *
     * @return HasMany<RefreshToken, User>
     */
    private function liveHeads(User $user): HasMany
    {
        return $user->refreshTokens()
            ->whereNotNull('session_id')
            ->where('revoked', false)
            ->where('expires_at', '>', now());
    }

    /**
     * End one session: delete its access token, its refresh tokens, and the
     * push token of the device it was — a signed-out phone must stop showing
     * the person's notifications.
     *
     * ⚠️ DELETED, NOT MARKED REVOKED. `revoked = true` is what replay
     * detection reads as "this token was rotated, so whoever presents it again
     * stole it" — and answers by ending every session the user has. A device
     * whose session was ended elsewhere does not know it, and its next refresh
     * is routine. Left revoked, that refresh would end all the user's other
     * sessions, including the one that just ended this one. Deleted, the token
     * is simply unknown: a 401, and nothing else happens.
     *
     * So `revoked` keeps exactly one meaning — rotated — and only rotation and
     * the theft response itself set it.
     */
    private function endSession(User $user, string $sessionId): void
    {
        $user->tokens()->where('name', self::accessTokenName($sessionId))->delete();

        $user->refreshTokens()->where('session_id', $sessionId)->delete();
        $user->deviceTokens()->where('session_id', $sessionId)->delete();
    }

    /**
     * The session a new row continues: $previous's, or a fresh one when there
     * is no previous row or it predates sessions.
     *
     * @return array{session_id: string, device_name: ?string, platform: ?DevicePlatform, signed_in_at: mixed}
     */
    private function sessionOf(?RefreshToken $previous): array
    {
        return [
            'session_id'   => $previous->session_id ?? (string) Str::uuid(),
            'device_name'  => $previous?->device_name,
            'platform'     => $previous?->platform,
            'signed_in_at' => $previous?->signed_in_at ?? now(),
        ];
    }

    /**
     * Create a short-lived Sanctum access token for one session.
     *
     * ⚙️ Deletes only THIS session's previous access token (2026-10-10). It
     * used to delete every access token the user held, so each refresh on one
     * device signed every other device out until it refreshed in turn.
     */
    private function createAccessToken(User $user, string $sessionId): string
    {
        $name = self::accessTokenName($sessionId);

        $user->tokens()->where('name', $name)->delete();

        $minutes = $this->accessTokenLifetimeMinutes();

        $token = $user->createToken(
            name: $name,
            abilities: self::DEFAULT_TOKEN_ABILITIES,
            // Null means "no per-token expiry" — the same thing config/sanctum's
            // null means — rather than a silently different lifetime here.
            expiresAt: $minutes === null ? null : now()->addMinutes($minutes),
        );

        return $token->plainTextToken;
    }

    /**
     * Create a long-lived refresh token in $session.
     *
     * Generates a cryptographically random token, stores only its hash.
     * Returns the plaintext token (only available at creation time).
     *
     * @param  array{session_id: string, device_name: ?string, platform: ?DevicePlatform, signed_in_at: mixed}  $session
     */
    private function createRefreshToken(User $user, array $session): string
    {
        $plainToken = Str::random(64);

        RefreshToken::create([
            ...$session,
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
