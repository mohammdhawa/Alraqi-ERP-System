<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sessions — one per signed-in device (work order §4.3, mapping §7).
 *
 * A session is a refresh-token family: login starts one, every refresh carries
 * it forward, and the access token belongs to it. That is what lets a person
 * see "my phone, my office browser" and end one without the other — and what
 * stops a phone and the dashboard, signed in as the same person, from
 * evicting each other's access token on every refresh.
 */
class SessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        User::factory()->create(['email' => 'nour@example.com', 'password' => 'password123']);
    }

    /** @return array{access_token: string, refresh_token: string} */
    private function signIn(?string $device = null, ?string $platform = null): array
    {
        $this->fresh();

        return $this->postJson('/api/auth/login', array_filter([
            'login'       => 'nour@example.com',
            'password'    => 'password123',
            'device_name' => $device,
            'platform'    => $platform,
        ]))->assertOk()->json('data');
    }

    /** Laravel reuses one app per test, so the guard must forget the last caller. */
    private function fresh(): void
    {
        $this->app['auth']->forgetGuards();
    }

    private function as(array $tokens): self
    {
        $this->fresh();

        return $this->withToken($tokens['access_token']);
    }

    // ── Listing ──────────────────────────────────────────────────────────

    public function test_a_sign_in_is_listed_with_the_device_it_came_from(): void
    {
        $phone = $this->signIn('Galaxy A54', 'android');

        $this->as($phone)->getJson('/api/auth/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.device_name', 'Galaxy A54')
            ->assertJsonPath('data.0.platform', 'android')
            ->assertJsonPath('data.0.is_current', true)
            ->assertJsonStructure(['data' => [['id', 'device_name', 'platform', 'signed_in_at', 'last_used_at', 'is_current']]]);
    }

    public function test_each_sign_in_is_its_own_session(): void
    {
        $phone = $this->signIn('Galaxy A54', 'android');
        $this->signIn('Chrome', 'web');

        $sessions = $this->as($phone)->getJson('/api/auth/sessions')->assertOk()->json('data');

        $this->assertCount(2, $sessions);
        $this->assertSame([false, true], collect($sessions)->sortBy('device_name')->pluck('is_current')->values()->all(),
            'Chrome is not the caller; the phone is');
    }

    public function test_a_refresh_continues_the_session_rather_than_starting_one(): void
    {
        $phone = $this->signIn('Galaxy A54', 'android');
        $before = $this->as($phone)->getJson('/api/auth/sessions')->json('data.0.id');

        $this->fresh();
        $rotated = $this->postJson('/api/auth/refresh', ['refresh_token' => $phone['refresh_token']])
            ->assertOk()->json('data');

        $this->as($rotated)->getJson('/api/auth/sessions')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $before)
            ->assertJsonPath('data.0.device_name', 'Galaxy A54');
    }

    public function test_the_session_keeps_its_sign_in_time_after_old_rows_are_pruned(): void
    {
        $phone = $this->signIn('Galaxy A54', 'android');
        $signedInAt = $this->as($phone)->getJson('/api/auth/sessions')->assertOk()->json('data.0.signed_in_at');
        $this->assertNotNull($signedInAt);

        $this->travel(2)->hours();
        $this->fresh();
        $rotated = $this->postJson('/api/auth/refresh', ['refresh_token' => $phone['refresh_token']])->json('data');
        DB::table('refresh_tokens')->where('revoked', true)->delete();

        $this->as($rotated)->getJson('/api/auth/sessions')->assertJsonPath('data.0.signed_in_at', $signedInAt);
    }

    // ── Two devices at once ──────────────────────────────────────────────

    public function test_signing_in_on_a_second_device_leaves_the_first_signed_in(): void
    {
        $phone = $this->signIn('Galaxy A54', 'android');
        $this->signIn('Chrome', 'web');

        $this->as($phone)->getJson('/api/auth/me')->assertOk();
    }

    public function test_refreshing_one_device_leaves_the_other_signed_in(): void
    {
        $phone = $this->signIn('Galaxy A54', 'android');
        $browser = $this->signIn('Chrome', 'web');

        $this->fresh();
        $this->postJson('/api/auth/refresh', ['refresh_token' => $browser['refresh_token']])->assertOk();

        $this->as($phone)->getJson('/api/auth/me')->assertOk();
    }

    // ── Ending a session ─────────────────────────────────────────────────

    public function test_ending_a_session_signs_that_device_out_and_no_other(): void
    {
        $phone = $this->signIn('Galaxy A54', 'android');
        $browser = $this->signIn('Chrome', 'web');
        $phoneId = collect($this->as($browser)->getJson('/api/auth/sessions')->json('data'))
            ->firstWhere('device_name', 'Galaxy A54')['id'];

        $this->as($browser)->deleteJson("/api/auth/sessions/{$phoneId}")->assertOk();

        $this->as($phone)->getJson('/api/auth/me')->assertUnauthorized();
        $this->fresh();
        $this->postJson('/api/auth/refresh', ['refresh_token' => $phone['refresh_token']])->assertUnauthorized();
        $this->as($browser)->getJson('/api/auth/me')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['event' => 'session_revoked']);
    }

    public function test_another_accounts_session_is_not_found(): void
    {
        $theirs = $this->signIn('Galaxy A54', 'android');
        $theirId = $this->as($theirs)->getJson('/api/auth/sessions')->assertOk()->json('data.0.id');

        $intruder = User::factory()->create(['email' => 'other@example.com', 'password' => 'password123']);
        $this->fresh();
        $this->actingAs($intruder, 'sanctum')->deleteJson("/api/auth/sessions/{$theirId}")->assertNotFound();

        $this->as($theirs)->getJson('/api/auth/me')->assertOk();
    }

    public function test_an_ended_session_is_no_longer_listed(): void
    {
        $phone = $this->signIn('Galaxy A54', 'android');
        $browser = $this->signIn('Chrome', 'web');
        $phoneId = collect($this->as($browser)->getJson('/api/auth/sessions')->json('data'))
            ->firstWhere('device_name', 'Galaxy A54')['id'];

        $this->as($browser)->deleteJson("/api/auth/sessions/{$phoneId}")->assertOk();

        $this->as($browser)->getJson('/api/auth/sessions')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.device_name', 'Chrome');
        $this->as($browser)->deleteJson("/api/auth/sessions/{$phoneId}")->assertNotFound();
    }

    public function test_logout_ends_only_the_callers_session(): void
    {
        $phone = $this->signIn('Galaxy A54', 'android');
        $browser = $this->signIn('Chrome', 'web');

        $this->as($phone)->postJson('/api/auth/logout')->assertOk();

        $this->as($phone)->getJson('/api/auth/me')->assertUnauthorized();
        $this->as($browser)->getJson('/api/auth/me')->assertOk();
    }

    public function test_logout_all_ends_every_session(): void
    {
        $phone = $this->signIn('Galaxy A54', 'android');
        $browser = $this->signIn('Chrome', 'web');

        $this->as($phone)->postJson('/api/auth/logout-all')->assertOk();

        $this->as($phone)->getJson('/api/auth/me')->assertUnauthorized();
        $this->as($browser)->getJson('/api/auth/me')->assertUnauthorized();
        $this->fresh();
        $this->postJson('/api/auth/refresh', ['refresh_token' => $browser['refresh_token']])->assertUnauthorized();
        $this->assertDatabaseHas('audit_logs', ['event' => 'user_logged_out_everywhere']);
    }

    public function test_changing_the_password_keeps_the_callers_session_and_ends_the_rest(): void
    {
        $phone = $this->signIn('Galaxy A54', 'android');
        $browser = $this->signIn('Chrome', 'web');

        $new = $this->as($phone)->postJson('/api/auth/password', [
            'current_password'      => 'password123',
            'password'              => 'a-new-password-9',
            'password_confirmation' => 'a-new-password-9',
        ])->assertOk()->json('data');

        $this->as($browser)->getJson('/api/auth/me')->assertUnauthorized();
        $this->as($new)->getJson('/api/auth/sessions')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.device_name', 'Galaxy A54');
    }

    // ── An ended session is not a stolen one ─────────────────────────────

    public function test_a_signed_out_device_refreshing_is_not_treated_as_theft(): void
    {
        $phone = $this->signIn('Galaxy A54', 'android');
        $browser = $this->signIn('Chrome', 'web');
        $phoneId = collect($this->as($browser)->getJson('/api/auth/sessions')->json('data'))
            ->firstWhere('device_name', 'Galaxy A54')['id'];
        $this->as($browser)->deleteJson("/api/auth/sessions/{$phoneId}")->assertOk();

        // The phone does not know it was signed out; its next refresh is
        // routine, not a replay, and must not end the browser's session.
        $this->fresh();
        $this->postJson('/api/auth/refresh', ['refresh_token' => $phone['refresh_token']])->assertUnauthorized();

        $this->as($browser)->getJson('/api/auth/me')->assertOk();
        $this->assertDatabaseMissing('audit_logs', ['event' => 'refresh_token_replay_detected']);
    }

    public function test_another_device_refreshing_after_a_password_change_leaves_the_changer_signed_in(): void
    {
        $phone = $this->signIn('Galaxy A54', 'android');
        $browser = $this->signIn('Chrome', 'web');
        $new = $this->as($phone)->postJson('/api/auth/password', [
            'current_password'      => 'password123',
            'password'              => 'a-new-password-9',
            'password_confirmation' => 'a-new-password-9',
        ])->assertOk()->json('data');

        $this->fresh();
        $this->postJson('/api/auth/refresh', ['refresh_token' => $browser['refresh_token']])->assertUnauthorized();

        $this->as($new)->getJson('/api/auth/me')->assertOk();
        $this->assertDatabaseMissing('audit_logs', ['event' => 'refresh_token_replay_detected']);
    }

    // ── Input ────────────────────────────────────────────────────────────

    public function test_device_details_are_optional(): void
    {
        $tokens = $this->signIn();

        $this->as($tokens)->getJson('/api/auth/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.device_name', null)
            ->assertJsonPath('data.0.platform', null);
    }

    public function test_an_unknown_platform_is_refused(): void
    {
        $this->postJson('/api/auth/login', [
            'login'    => 'nour@example.com',
            'password' => 'password123',
            'platform' => 'symbian',
        ])->assertUnprocessable()->assertJsonValidationErrors('platform');
    }
}
