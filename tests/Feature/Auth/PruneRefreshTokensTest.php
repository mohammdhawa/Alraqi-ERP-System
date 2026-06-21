<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * auth:prune-refresh-tokens coverage.
 *
 * Locks in that the prune command is (a) actually registered/runnable — proving
 * its namespace matches its path — (b) prunes only tokens expired beyond the
 * retention window, and (c) is wired into the daily scheduler.
 */
class PruneRefreshTokensTest extends TestCase
{
    use RefreshDatabase;

    private function insertToken(User $user, string $hash, bool $revoked, \DateTimeInterface $expiresAt): void
    {
        DB::table('refresh_tokens')->insert([
            'user_id'    => $user->id,
            'token_hash' => $hash,
            'revoked'    => $revoked,
            'expires_at' => $expiresAt,
            'created_at' => now()->subDays(30),
        ]);
    }

    public function test_prunes_only_tokens_expired_beyond_retention(): void
    {
        $user = User::factory()->create();

        // Beyond the 7-day retention window -> pruned.
        $this->insertToken($user, str_repeat('a', 64), revoked: true, expiresAt: now()->subDays(10));
        $this->insertToken($user, str_repeat('b', 64), revoked: false, expiresAt: now()->subDays(10));
        // Within retention / still active -> kept.
        $this->insertToken($user, str_repeat('c', 64), revoked: true, expiresAt: now()->subDays(2));
        $this->insertToken($user, str_repeat('d', 64), revoked: false, expiresAt: now()->addDays(5));

        $this->artisan('auth:prune-refresh-tokens')
            ->expectsOutputToContain('Pruned 2 expired refresh tokens.')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('refresh_tokens', ['token_hash' => str_repeat('a', 64)]);
        $this->assertDatabaseMissing('refresh_tokens', ['token_hash' => str_repeat('b', 64)]);
        $this->assertDatabaseHas('refresh_tokens', ['token_hash' => str_repeat('c', 64)]);
        $this->assertDatabaseHas('refresh_tokens', ['token_hash' => str_repeat('d', 64)]);
    }

    public function test_respects_custom_retention_window(): void
    {
        $user = User::factory()->create();

        // Expired 2 days ago: kept under the default 7-day window, but pruned
        // when the retention window is narrowed to 1 day via --days.
        $this->insertToken($user, str_repeat('e', 64), revoked: true, expiresAt: now()->subDays(2));

        $this->artisan('auth:prune-refresh-tokens', ['--days' => 1])->assertExitCode(0);

        $this->assertDatabaseMissing('refresh_tokens', ['token_hash' => str_repeat('e', 64)]);
    }

    public function test_command_is_scheduled_daily(): void
    {
        $schedule = app(Schedule::class);

        $isScheduled = collect($schedule->events())
            ->contains(fn ($event) => str_contains((string) $event->command, 'auth:prune-refresh-tokens'));

        $this->assertTrue($isScheduled, 'The prune command should be registered in the scheduler.');
    }
}
