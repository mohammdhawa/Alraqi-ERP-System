<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\DeviceToken;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Push tokens — one per device, owned by a session (work order §4.3,
 * mapping §7, EMPLOYEE_APP_REQUIREMENTS.md §6.4).
 *
 * Legacy kept one `users.fcm_token`, so a second phone silently replaced the
 * first. Here each device registers its own token, and the token belongs to
 * the session it was registered from: when that session ends, the device
 * stops receiving pushes — a revoked phone must not keep showing a person's
 * notifications on its lock screen.
 */
class DeviceTokenTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email' => 'nour@example.com', 'password' => 'password123']);
    }

    /** @return array{access_token: string, refresh_token: string} */
    private function signIn(string $device, string $email = 'nour@example.com'): array
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/auth/login', [
            'login' => $email, 'password' => 'password123', 'device_name' => $device, 'platform' => 'android',
        ])->assertOk()->json('data');
    }

    private function as(array $tokens): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($tokens['access_token']);
    }

    private function register(array $session, string $token): void
    {
        $this->as($session)->postJson('/api/me/device-tokens', ['token' => $token, 'platform' => 'android'])
            ->assertOk();
    }

    // ── Registering ──────────────────────────────────────────────────────

    public function test_a_device_registers_its_push_token(): void
    {
        $phone = $this->signIn('Galaxy A54');

        $this->register($phone, 'fcm-token-phone');

        $row = DeviceToken::sole();
        $this->assertSame($this->user->id, $row->user_id);
        $this->assertSame('android', $row->platform->value);
        $this->assertNotNull($row->session_id);
        $this->assertNotNull($row->last_seen_at);
    }

    public function test_two_devices_keep_two_tokens(): void
    {
        $this->register($this->signIn('Galaxy A54'), 'fcm-token-phone');
        $this->register($this->signIn('iPhone 13'), 'fcm-token-tablet');

        $this->assertSame(2, $this->user->deviceTokens()->count());
    }

    public function test_registering_the_same_token_again_does_not_duplicate_it(): void
    {
        $phone = $this->signIn('Galaxy A54');
        $this->register($phone, 'fcm-token-phone');
        $firstSeen = DeviceToken::sole()->last_seen_at;

        // Inside the access token's lifetime — the app re-registers on start.
        $this->travel(10)->minutes();
        $this->register($phone, 'fcm-token-phone');

        $this->assertSame(1, DeviceToken::count());
        $this->assertTrue(DeviceToken::sole()->last_seen_at->isAfter($firstSeen));
    }

    public function test_a_phone_that_changes_hands_pushes_to_its_new_user_only(): void
    {
        $this->register($this->signIn('Galaxy A54'), 'fcm-token-phone');
        User::factory()->create(['email' => 'samer@example.com', 'password' => 'password123']);

        $this->register($this->signIn('Galaxy A54', 'samer@example.com'), 'fcm-token-phone');

        $this->assertSame(0, $this->user->deviceTokens()->count());
        $this->assertSame('samer@example.com', DeviceToken::sole()->user->email);
    }

    public function test_the_token_and_a_known_platform_are_required(): void
    {
        $phone = $this->signIn('Galaxy A54');

        $this->as($phone)->postJson('/api/me/device-tokens', ['platform' => 'symbian'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token', 'platform']);
    }

    public function test_registering_requires_authentication(): void
    {
        $this->postJson('/api/me/device-tokens', ['token' => 'x', 'platform' => 'android'])->assertUnauthorized();
    }

    // ── Unregistering ────────────────────────────────────────────────────

    public function test_a_device_can_unregister_its_token(): void
    {
        $phone = $this->signIn('Galaxy A54');
        $this->register($phone, 'fcm-token-phone');

        $this->as($phone)->deleteJson('/api/me/device-tokens', ['token' => 'fcm-token-phone'])->assertOk();

        $this->assertSame(0, DeviceToken::count());
    }

    public function test_unregistering_someone_elses_token_changes_nothing(): void
    {
        $this->register($this->signIn('Galaxy A54'), 'fcm-token-phone');
        User::factory()->create(['email' => 'samer@example.com', 'password' => 'password123']);

        $this->as($this->signIn('Chrome', 'samer@example.com'))
            ->deleteJson('/api/me/device-tokens', ['token' => 'fcm-token-phone'])
            ->assertOk();

        $this->assertSame(1, $this->user->deviceTokens()->count());
    }

    // ── A session's end ends its pushes ──────────────────────────────────

    public function test_ending_a_session_removes_that_devices_token_only(): void
    {
        $phone = $this->signIn('Galaxy A54');
        $browser = $this->signIn('Chrome');
        $this->register($phone, 'fcm-token-phone');
        $this->register($browser, 'fcm-token-browser');
        $phoneId = collect($this->as($browser)->getJson('/api/auth/sessions')->json('data'))
            ->firstWhere('device_name', 'Galaxy A54')['id'];

        $this->as($browser)->deleteJson("/api/auth/sessions/{$phoneId}")->assertOk();

        $this->assertSame(['fcm-token-browser'], DeviceToken::pluck('token')->all());
    }

    public function test_logout_removes_this_devices_token(): void
    {
        $phone = $this->signIn('Galaxy A54');
        $browser = $this->signIn('Chrome');
        $this->register($phone, 'fcm-token-phone');
        $this->register($browser, 'fcm-token-browser');

        $this->as($phone)->postJson('/api/auth/logout')->assertOk();

        $this->assertSame(['fcm-token-browser'], DeviceToken::pluck('token')->all());
    }

    public function test_logout_all_removes_every_token(): void
    {
        $phone = $this->signIn('Galaxy A54');
        $this->register($phone, 'fcm-token-phone');
        $this->register($this->signIn('Chrome'), 'fcm-token-browser');

        $this->as($phone)->postJson('/api/auth/logout-all')->assertOk();

        $this->assertSame(0, DeviceToken::count());
    }

    public function test_a_password_change_keeps_only_the_changers_token(): void
    {
        $phone = $this->signIn('Galaxy A54');
        $this->register($phone, 'fcm-token-phone');
        $this->register($this->signIn('Chrome'), 'fcm-token-browser');

        $this->as($phone)->postJson('/api/auth/password', [
            'current_password'      => 'password123',
            'password'              => 'a-new-password-9',
            'password_confirmation' => 'a-new-password-9',
        ])->assertOk();

        $this->assertSame(['fcm-token-phone'], DeviceToken::pluck('token')->all());
    }

    public function test_terminating_the_employee_removes_their_tokens(): void
    {
        $employee = Employee::factory()->named('نور الشامي')->create();
        $this->user->update(['employee_id' => $employee->id]);
        $this->register($this->signIn('Galaxy A54'), 'fcm-token-phone');

        $employee->update(['status' => EmployeeStatus::Terminated]);

        $this->assertSame(0, DeviceToken::count());
    }
}
