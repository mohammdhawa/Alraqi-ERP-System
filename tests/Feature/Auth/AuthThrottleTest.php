<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Login rate-limit coverage.
 *
 * Unlike AuthTest, this class deliberately does NOT relax the rate limiters:
 * it asserts the production `throttle:auth-login` limiter (5/min per IP,
 * registered in AppServiceProvider) actually rejects a 6th attempt. This is the
 * regression guard that keeps brute-force protection from silently regressing.
 */
class AuthThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_is_throttled_after_five_attempts(): void
    {
        User::factory()->create(['email' => 'user@example.com']);

        $payload = ['email' => 'user@example.com', 'password' => 'wrong-password'];

        // The first five attempts reach the controller (401 invalid credentials).
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', $payload)->assertUnauthorized();
        }

        // The sixth, within the same minute and from the same IP, is rejected by
        // the throttle middleware before reaching the controller.
        $this->postJson('/api/auth/login', $payload)
            ->assertStatus(429)
            // 429 used to escape the envelope — it was one of the two cases
            // API_DOCUMENTATION.md documented as bare. It no longer is.
            ->assertJsonPath('success', false)
            ->assertHeader('Retry-After');
    }

    /**
     * The BASELINE limiter on the whole api group actually exists.
     *
     * This is the regression guard on GAP-1. Laravel 12's default `api` group
     * contains only SubstituteBindings; ->throttleApi() was never called, so 26
     * of 28 routes had no rate limit whatsoever — while a comment in
     * ModuleServiceProvider and another in the Auth route file both described a
     * throttle that was not there. Two pieces of prose asserted it and nothing
     * checked it, which is precisely why it went unnoticed.
     */
    public function test_the_api_group_is_rate_limited(): void
    {
        $this->actingAsAdmin();

        // The limiter allows 60/min; the 61st must be rejected.
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/departments')->assertOk();
        }

        $this->getJson('/api/departments')
            ->assertStatus(429)
            ->assertJsonPath('success', false);
    }

    /**
     * UNAUTHENTICATED traffic is rate limited — the case the limiter is
     * actually for.
     *
     * Adding `throttleApi()` was not sufficient on its own. Laravel sorts route
     * middleware by `$middlewarePriority`, whose default puts
     * `AuthenticatesRequests` BEFORE `ThrottleRequests`, so every protected
     * route resolved to `auth:sanctum → throttle:api` and an unauthenticated
     * request 401'd before the limiter ever ran. Probed at the time: 70
     * unauthenticated requests, 70 × 401, zero 429. The rate limit existed and
     * was unreachable by hostile traffic — which is worse than no rate limit,
     * because the route list and the config both said it was there.
     *
     * bootstrap/app.php now reorders the priority list. If that regresses — on
     * a framework upgrade, say — this test fails.
     */
    public function test_unauthenticated_traffic_is_rate_limited(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/departments')->assertUnauthorized();
        }

        $this->getJson('/api/departments')
            ->assertStatus(429)
            ->assertJsonPath('success', false);
    }

    /**
     * Moving the throttle ahead of authentication must not silently downgrade
     * the key from per-user to per-IP.
     *
     * The default guard is `web`, so a bare `$request->user()` inside the
     * limiter would now resolve nothing and key every caller by IP — putting a
     * whole office behind one 60/min budget. The limiter names the sanctum
     * guard explicitly; this proves two users on the same IP keep separate
     * budgets.
     */
    public function test_authenticated_callers_keep_separate_budgets(): void
    {
        $this->actingAsAdmin();

        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/departments')->assertOk();
        }

        $this->getJson('/api/departments')->assertStatus(429);

        // A different user, same IP: unaffected.
        $this->app['auth']->forgetGuards();
        $this->actingAsAdmin();

        $this->getJson('/api/departments')->assertOk();
    }

    /**
     * The limiter is keyed on IP **and** email.
     *
     * Keying on IP alone means one attacker behind a company NAT locks out every
     * legitimate user sharing that address — the brute-force protection becomes
     * the denial of service. This asserts that exhausting one account's budget
     * leaves a different account still able to try.
     */
    public function test_throttling_one_account_does_not_lock_out_another(): void
    {
        User::factory()->create(['email' => 'victim@example.com']);
        User::factory()->create(['email' => 'bystander@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'victim@example.com', 'password' => 'wrong-password',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', [
            'email' => 'victim@example.com', 'password' => 'wrong-password',
        ])->assertStatus(429);

        // Same IP, different account: unaffected.
        $this->postJson('/api/auth/login', [
            'email' => 'bystander@example.com', 'password' => 'wrong-password',
        ])->assertUnauthorized();
    }

    /**
     * Casing must not buy extra attempts — otherwise VICTIM@example.com is five
     * more tries, and so is Victim@example.com, and so on.
     */
    public function test_email_casing_does_not_reset_the_budget(): void
    {
        User::factory()->create(['email' => 'victim@example.com']);

        foreach (['victim@example.com', 'VICTIM@example.com', 'Victim@Example.com'] as $variant) {
            for ($i = 0; $i < 2; $i++) {
                $this->postJson('/api/auth/login', ['email' => $variant, 'password' => 'wrong-password']);
            }
        }

        $this->postJson('/api/auth/login', [
            'email' => 'victim@example.com', 'password' => 'wrong-password',
        ])->assertStatus(429);
    }
}
