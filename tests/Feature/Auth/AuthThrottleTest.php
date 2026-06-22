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
        $this->postJson('/api/auth/login', $payload)->assertStatus(429);
    }
}
