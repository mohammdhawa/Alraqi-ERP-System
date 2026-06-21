<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Core Auth flow coverage.
 *
 * Exercises the full Route -> Controller -> AuthService -> DB path through
 * real HTTP requests (not mocked) so token issuance, rotation, revocation,
 * and audit logging are verified end to end.
 *
 * NOTE: The auth rate limiters are relaxed here so repeated login/refresh
 * calls across tests (which all share the 127.0.0.1 test IP) don't trip the
 * intentionally low production limits. The real throttle middleware still
 * runs, exercising the full middleware stack including 'audit'.
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::for('auth-login', fn () => Limit::none());
        RateLimiter::for('auth-refresh', fn () => Limit::none());
    }

    /**
     * Forget resolved auth guards so the next request re-resolves the user
     * from its token, exactly as a fresh request would in production.
     *
     * Laravel reuses a single application instance across the simulated
     * requests in one test method, so the Sanctum guard would otherwise
     * cache the user from a previous request and mask token revocation.
     */
    private function asFreshRequest(): void
    {
        $this->app['auth']->forgetGuards();
    }

    private function createUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'email'    => 'user@example.com',
            'password' => 'password123',
        ], $overrides));
    }

    /**
     * Log in and return the decoded `data` payload (user + tokens).
     *
     * @return array<string, mixed>
     */
    private function loginAs(string $email = 'user@example.com', string $password = 'password123'): array
    {
        return $this->postJson('/api/auth/login', [
            'email'    => $email,
            'password' => $password,
        ])->json('data');
    }

    public function test_login_succeeds_with_valid_credentials(): void
    {
        $this->createUser();

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'user@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', 'user@example.com')
            ->assertJsonStructure([
                'data' => ['user', 'access_token', 'refresh_token', 'expires_in'],
            ]);

        $this->assertDatabaseHas('audit_logs', ['event' => 'user_logged_in']);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->createUser();

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'user@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid credentials.');

        // No tokens should have been issued.
        $this->assertDatabaseCount('refresh_tokens', 0);
    }

    public function test_login_fails_for_disabled_account(): void
    {
        $this->createUser(['is_active' => false]);

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'user@example.com',
            'password' => 'password123',
        ]);

        $response->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This account has been disabled.');

        $this->assertDatabaseCount('refresh_tokens', 0);
    }

    public function test_refresh_rotates_the_token_pair(): void
    {
        $this->createUser();
        $first = $this->loginAs();

        $response = $this->postJson('/api/auth/refresh', [
            'refresh_token' => $first['refresh_token'],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['access_token', 'refresh_token']]);

        $new = $response->json('data');

        // The rotated token must differ from the original.
        $this->assertNotSame($first['refresh_token'], $new['refresh_token']);

        // The original refresh token is now revoked, the new one is active.
        $this->assertSame(1, DB::table('refresh_tokens')->where('revoked', true)->count());
        $this->assertSame(1, DB::table('refresh_tokens')->where('revoked', false)->count());

        $this->assertDatabaseHas('audit_logs', ['event' => 'tokens_refreshed']);
    }

    public function test_reusing_a_revoked_refresh_token_terminates_all_sessions(): void
    {
        $this->createUser();
        $first = $this->loginAs();

        // First refresh succeeds and revokes the original token.
        $rotated = $this->postJson('/api/auth/refresh', [
            'refresh_token' => $first['refresh_token'],
        ])->json('data');

        // Replaying the original (now revoked) token must be rejected...
        $this->asFreshRequest();
        $this->postJson('/api/auth/refresh', [
            'refresh_token' => $first['refresh_token'],
        ])->assertUnauthorized()
            ->assertJsonPath('success', false);

        // ...and theft detection must revoke the freshly rotated token too.
        $this->asFreshRequest();
        $this->postJson('/api/auth/refresh', [
            'refresh_token' => $rotated['refresh_token'],
        ])->assertUnauthorized();

        $this->assertSame(0, DB::table('refresh_tokens')->where('revoked', false)->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'refresh_token_replay_detected']);
    }

    public function test_logout_revokes_access_and_refresh_tokens(): void
    {
        $this->createUser();
        $tokens = $this->loginAs();

        $logout = $this->withToken($tokens['access_token'])
            ->postJson('/api/auth/logout');

        $logout->assertOk()->assertJsonPath('success', true);

        // Access token is revoked: protected routes now reject it.
        $this->asFreshRequest();
        $this->withToken($tokens['access_token'])
            ->getJson('/api/auth/me')
            ->assertUnauthorized();

        // Refresh token is revoked: it can no longer be exchanged.
        $this->asFreshRequest();
        $this->postJson('/api/auth/refresh', [
            'refresh_token' => $tokens['refresh_token'],
        ])->assertUnauthorized();

        $this->assertSame(0, DB::table('refresh_tokens')->where('revoked', false)->count());
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user_logged_out']);
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $this->createUser(['name' => 'Jane Doe']);
        $tokens = $this->loginAs();

        $response = $this->withToken($tokens['access_token'])
            ->getJson('/api/auth/me');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email', 'user@example.com')
            ->assertJsonPath('data.name', 'Jane Doe')
            ->assertJsonMissingPath('data.password');
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_audit_middleware_records_authenticated_requests(): void
    {
        $this->createUser();
        $tokens = $this->loginAs();

        $this->withToken($tokens['access_token'])->getJson('/api/auth/me');

        // The 'audit' middleware on protected routes logs a request-level event.
        $this->assertDatabaseHas('audit_logs', ['event' => 'api_request']);
    }
}
