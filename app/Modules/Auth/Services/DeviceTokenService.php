<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Enums\DevicePlatform;
use App\Modules\Auth\Models\DeviceToken;
use App\Modules\Auth\Models\User;

/**
 * DeviceTokenService — registering and unregistering a device's push token.
 *
 * Self-scoped throughout: every method acts on the caller's own devices. The
 * cleanup when a session ends lives in AuthService::endSession() and
 * User::disable(), beside the token deletions it accompanies.
 */
class DeviceTokenService
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    /**
     * Register $token as one of the caller's devices, in the caller's session.
     *
     * Upserted on the token alone: re-registering on every app start only
     * refreshes `last_seen_at`, and a token another account registered — the
     * same phone, now someone else's — moves to the caller.
     */
    public function register(User $user, string $token, DevicePlatform $platform): DeviceToken
    {
        return DeviceToken::query()->updateOrCreate(
            ['token' => $token],
            [
                'user_id'      => $user->getKey(),
                'platform'     => $platform,
                'session_id'   => $this->authService->currentSessionId($user),
                'last_seen_at' => now(),
            ],
        );
    }

    /**
     * Stop pushing to $token — if it is one of the caller's.
     *
     * Idempotent, and silent about tokens that belong to someone else: the
     * answer is the same either way, so it reveals nothing about whether the
     * token is registered.
     */
    public function unregister(User $user, string $token): void
    {
        $user->deviceTokens()->where('token', $token)->delete();
    }
}
