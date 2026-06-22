<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * PruneExpiredRefreshTokens
 *
 * Scheduled command to clean up expired/revoked refresh tokens.
 *
 * WHY:
 * - Refresh tokens accumulate quickly (every login + every refresh creates one).
 * - Revoked tokens are kept for 7 days after expiry for forensic analysis.
 * - Without pruning, the table grows indefinitely and slows down queries.
 *
 * SCHEDULE:
 * In routes/console.php (Laravel 12):
 *   Schedule::command('auth:prune-refresh-tokens')->daily();
 *
 * Or in app/Console/Kernel.php if using the older scheduling approach.
 */
class PruneExpiredRefreshTokens extends Command
{
    protected $signature = 'auth:prune-refresh-tokens
                            {--days=7 : Keep revoked tokens for this many days after expiry}';

    protected $description = 'Remove expired and revoked refresh tokens older than the retention period.';

    public function handle(): int
    {
        $retentionDays = (int) $this->option('days');

        $deleted = DB::table('refresh_tokens')
            ->where('revoked', true)
            ->where('expires_at', '<', now()->subDays($retentionDays))
            ->delete();

        // Also remove non-revoked tokens that expired beyond retention
        $deleted += DB::table('refresh_tokens')
            ->where('expires_at', '<', now()->subDays($retentionDays))
            ->delete();

        $this->info("Pruned {$deleted} expired refresh tokens.");

        return self::SUCCESS;
    }
}