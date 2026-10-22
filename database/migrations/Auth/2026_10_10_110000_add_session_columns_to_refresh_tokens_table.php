<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Give refresh tokens a session (work order §4.3, mapping §7).
 *
 * ───────────────────────────────────────────────────────────────────────────
 * THE PROBLEM THIS SOLVES
 * ───────────────────────────────────────────────────────────────────────────
 * Every refresh writes a new row and revokes the old one, and nothing linked
 * the two — so "the phone" and "the office browser" did not exist as things a
 * person could list or end. And because AuthService deleted ALL of a user's
 * access tokens whenever it minted one, a phone and the dashboard signed in as
 * the same person evicted each other's access token on every refresh.
 *
 * `session_id` names the chain: login mints it, every rotation copies it
 * forward, and the access token is named after it, so ending a session ends
 * exactly one device.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * WHY `signed_in_at` IS CARRIED, AND `last_used_at` IS NOT A COLUMN
 * ───────────────────────────────────────────────────────────────────────────
 * The sign-in time looks derivable — MIN(created_at) over the session — but
 * auth:prune-refresh-tokens deletes old revoked rows, the first one included,
 * so it is copied onto every row instead.
 *
 * "Last used" is not stored here at all: Sanctum already stamps
 * `personal_access_tokens.last_used_at` on every authenticated request, and the
 * access token now belongs to one session. A copy here would be stale by up to
 * an access-token lifetime and a second place for one fact.
 *
 * NULLABLE because rows written before this migration have no device, and
 * each is backfilled as its own session.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refresh_tokens', function (Blueprint $table) {
            $table->uuid('session_id')->nullable()->after('user_id');
            $table->string('device_name', 100)->nullable()->after('session_id');
            $table->string('platform', 16)->nullable()->after('device_name');
            $table->timestamp('signed_in_at')->nullable()->after('platform');

            $table->index(['user_id', 'session_id']);
        });

        DB::table('refresh_tokens')->whereNull('session_id')->orderBy('id')
            ->each(function (object $row): void {
                DB::table('refresh_tokens')->where('id', $row->id)->update([
                    'session_id'   => (string) Str::uuid(),
                    'signed_in_at' => $row->created_at,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('refresh_tokens', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'session_id']);
            $table->dropColumn(['session_id', 'device_name', 'platform', 'signed_in_at']);
        });
    }
};
