<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * The access-token lifetime is ONE fact (P1), and the three things that read it
 * must agree.
 *
 * WHAT THIS GUARDS: the lifetime used to exist in four places — an
 * env-overridable `config/sanctum.expiration`, a hardcoded
 * `AuthService::ACCESS_TOKEN_EXPIRATION_MINUTES = 15`, and `15 * 60` written
 * twice in AuthController. Only the config one responded to the env var it
 * exposes, so `SANCTUM_TOKEN_EXPIRATION=5` produced a system where Sanctum's
 * guard rejected tokens at 5 minutes, the stored `expires_at` said 15, and the
 * API told clients 900 seconds. Every client would then schedule its refresh
 * for minute 15 on a token that died at minute 5.
 *
 * The non-default config value below is the whole point: with four hardcoded
 * copies the default case agrees by coincidence and only an override reveals
 * the drift.
 */
class AccessTokenLifetimeTest extends TestCase
{
    use RefreshDatabase;

    private function login(): array
    {
        User::factory()->create([
            'email'    => 'lifetime@example.com',
            'password' => 'secret-password',
        ]);

        return $this->postJson('/api/auth/login', [
            'login'    => 'lifetime@example.com',
            'password' => 'secret-password',
        ])->assertOk()->json('data');
    }

    public function test_advertised_expiry_matches_the_configured_lifetime(): void
    {
        config(['sanctum.expiration' => 15]);

        $this->assertSame(15 * 60, $this->login()['expires_in']);
    }

    public function test_a_non_default_configured_lifetime_is_honoured_everywhere(): void
    {
        // The case four hardcoded copies could never survive.
        config(['sanctum.expiration' => 5]);

        $data = $this->login();

        // 1. What the client is told.
        $this->assertSame(5 * 60, $data['expires_in'], 'expires_in ignored the configured lifetime');

        // 2. What is actually stamped on the token row — the value Sanctum's
        //    guard enforces. If these disagree, the client refreshes too late.
        $token = PersonalAccessToken::query()->latest('id')->first();

        $this->assertNotNull($token->expires_at);
        $this->assertEqualsWithDelta(
            5 * 60,
            now()->diffInSeconds($token->expires_at),
            10,
            'the stored expires_at disagrees with the advertised expires_in',
        );
    }

    public function test_refresh_advertises_the_same_lifetime_as_login(): void
    {
        config(['sanctum.expiration' => 7]);

        $login = $this->login();

        $refreshed = $this->postJson('/api/auth/refresh', [
            'refresh_token' => $login['refresh_token'],
        ])->assertOk()->json('data');

        // The second of the two hardcoded copies lived in refresh(), so this is
        // where the two endpoints used to be able to disagree with each other.
        $this->assertSame($login['expires_in'], $refreshed['expires_in']);
        $this->assertSame(7 * 60, $refreshed['expires_in']);
    }

    public function test_a_null_configured_lifetime_means_no_expiry_and_is_reported_as_such(): void
    {
        // Laravel's own default for sanctum.expiration is null — "tokens do not
        // expire on their own". Coercing that to a number here would advertise
        // an expiry the token does not have and make clients refresh forever.
        config(['sanctum.expiration' => null]);

        $this->assertNull($this->login()['expires_in']);
        $this->assertNull(PersonalAccessToken::query()->latest('id')->first()->expires_at);
    }
}
