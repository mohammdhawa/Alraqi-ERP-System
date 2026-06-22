<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Exceptions\AuthenticationException;
use App\Modules\Auth\Exceptions\InvalidRefreshTokenException;
use App\Modules\Auth\Exceptions\AccountDisabledException;
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
     * Access token lifetime in minutes.
     * Short-lived to limit exposure if intercepted.
     * 15 minutes balances security with UX (frequent refreshes are handled by the client).
     */
    private const ACCESS_TOKEN_EXPIRATION_MINUTES = 15;

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

    // Authenticate user and issue token pair.
    public function login(string $email, string $password): array
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            // SECURITY: Generic message prevents user enumeration.
            // Attacker cannot determine if the email exists.
            throw new AuthenticationException('Invalid credentials.');
        }

        if (! $user->isActive()) {
            throw new AccountDisabledException();
        }

        return DB::transaction(function () use ($user): array {
            $accessToken  = $this->createAccessToken($user);
            $refreshToken = $this->createRefreshToken($user);

            $this->auditLogService->logAction(
                event: 'user_logged_in',
                description: "User {$user->email} logged in.",
            );

            return [
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
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
     * @return array{access_token: string, refresh_token: string}
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
                    throw new InvalidRefreshTokenException('Invalid refresh token.');
                }

                // SECURITY: A revoked token being used again indicates token theft.
                // The actual revocation happens after this transaction (see above).
                if ($refreshToken->revoked) {
                    $replayUserId = $refreshToken->user_id;

                    throw new InvalidRefreshTokenException(
                        'Token has been revoked. All sessions have been terminated for security.'
                    );
                }

                if ($refreshToken->expires_at->isPast()) {
                    $refreshToken->update(['revoked' => true]);
                    throw new InvalidRefreshTokenException('Refresh token has expired.');
                }

                $user = $refreshToken->user;

                if (! $user || ! $user->isActive()) {
                    throw new InvalidRefreshTokenException('Account is not active.');
                }

                // Revoke the current refresh token (single-use)
                $refreshToken->update(['revoked' => true]);

                // Issue new pair
                $newAccessToken  = $this->createAccessToken($user);
                $newRefreshToken = $this->createRefreshToken($user);

                $this->auditLogService->logAction(
                    event: 'tokens_refreshed',
                    description: "Tokens rotated for user {$user->email}.",
                );

                return [
                    'access_token'  => $newAccessToken,
                    'refresh_token' => $newRefreshToken,
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
                description: "User {$user->email} logged out. All tokens revoked.",
            );
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
    private function createAccessToken(User $user): string
    {
        // Delete existing access tokens to prevent accumulation.
        // In a multi-device scenario, you might keep them and rely on expiry.
        // For an ERP with controlled access, cleanup is cleaner.
        $user->tokens()->delete();

        $token = $user->createToken(
            name: 'access_token',
            abilities: self::DEFAULT_TOKEN_ABILITIES,
            expiresAt: now()->addMinutes(self::ACCESS_TOKEN_EXPIRATION_MINUTES),
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