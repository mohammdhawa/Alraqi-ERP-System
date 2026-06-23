<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Requests\LoginRequest;
use App\Modules\Auth\Requests\RefreshTokenRequest;
use App\Modules\Auth\Resources\AuthResource;
use App\Modules\Auth\Services\AuthService;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * AuthController
 *
 * Thin controller: receives HTTP requests, delegates to AuthService,
 * formats responses. Zero business logic lives here.
 *
 * WHY thin controllers:
 * - Business logic in services is reusable (CLI commands, jobs, other controllers).
 * - Controllers are hard to unit test (need HTTP context); services are easy.
 * - When auth flow changes, you update AuthService, not the controller.
 * - SOLID: Single Responsibility — controller handles HTTP, service handles auth.
 *
 * ENDPOINTS:
 *   POST   /api/auth/login    → Authenticate and get tokens
 *   POST   /api/auth/refresh  → Rotate tokens
 *   POST   /api/auth/logout   → Revoke all tokens
 *   GET    /api/auth/me       → Get authenticated user profile
 */
class AuthController extends Controller
{
    use ApiRespond;

    public function __construct(
        private readonly AuthService $authService,
    ) {}

    /**
     * POST /api/auth/login
     *
     * Authenticates the user with email/password.
     * Returns access token + refresh token pair.
     *
     * The client should:
     * - Store access_token in memory (SPA) or secure storage (mobile).
     * - Store refresh_token in an httpOnly cookie (SPA) or secure storage (mobile).
     * - Send access_token in the Authorization: Bearer header on API requests.
     * - When a 401 is received, call /auth/refresh with the refresh_token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        // Auth failures (invalid credentials, disabled account) throw typed
        // exceptions that are rendered centrally in bootstrap/app.php. This
        // keeps the controller thin and prevents internal error disclosure.
        $result = $this->authService->login(
            email: $request->validated('email'),
            password: $request->validated('password'),
        );

        return $this->success(
            data: [
                'user'          => new AuthResource($result['user']),
                'access_token'  => $result['access_token'],
                'refresh_token' => $result['refresh_token'],
                'token_type'    => 'Bearer',
                'expires_in'    => 15 * 60, // seconds — helps client set a timer
            ],
            message: 'تم تسجيل الدخول بنجاح.',
        );
    }

    /**
     * POST /api/auth/refresh
     *
     * Exchanges a valid refresh token for a new access + refresh token pair.
     * The old refresh token is immediately revoked (single-use).
     *
     * NOTE: This endpoint is NOT behind auth:sanctum middleware because
     * the access token has expired — that's the whole point of refreshing.
     * Authentication here is done by validating the refresh token.
     */
    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        // InvalidRefreshTokenException is rendered centrally (see bootstrap/app.php).
        $result = $this->authService->refreshTokens(
            plainRefreshToken: $request->validated('refresh_token'),
        );

        return $this->success(
            data: [
                'access_token'  => $result['access_token'],
                'refresh_token' => $result['refresh_token'],
                'token_type'    => 'Bearer',
                'expires_in'    => 15 * 60,
            ],
            message: 'تم تحديث الرموز بنجاح.',
        );
    }

    /**
     * POST /api/auth/logout
     *
     * Revokes all access and refresh tokens for the authenticated user.
     * After this, the user must log in again on all devices.
     *
     * WHY POST not DELETE:
     * - POST is idempotent for this operation (calling twice is safe).
     * - Some corporate proxies/firewalls block DELETE requests.
     * - It's the industry convention for logout endpoints.
     */
    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return $this->success(message: 'تم تسجيل الخروج بنجاح.');
    }

    /**
     * GET /api/auth/me
     *
     * Returns the currently authenticated user's profile.
     * Used by frontends to verify the session and get user data.
     */
    public function me(Request $request): JsonResponse
    {
        return $this->success(
            data: new AuthResource($request->user()),
            message: 'تم جلب بيانات المستخدم الحالي.',
        );
    }
}