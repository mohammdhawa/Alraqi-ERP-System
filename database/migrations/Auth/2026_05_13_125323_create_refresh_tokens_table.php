<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create refresh_tokens table.
 *
 * SCHEMA DESIGN:
 *
 *   token_hash (UNIQUE INDEX):
 *   - SHA-256 hash of the plaintext token. 64 hex characters.
 *   - Unique index enables O(1) lookup during refresh.
 *   - Only the hash is stored; plaintext is never persisted.
 *
 *   user_id (INDEXED FK):
 *   - FK to users table with CASCADE delete.
 *   - Indexed for "revoke all tokens for user" queries.
 *   - CASCADE: if user is deleted, tokens are automatically cleaned up.
 *
 *   revoked (INDEXED):
 *   - Boolean flag. Set to true on rotation or explicit revocation.
 *   - Indexed because cleanup queries filter on this field.
 *
 *   expires_at (INDEXED):
 *   - Absolute expiration timestamp.
 *   - Indexed for scheduled cleanup of expired tokens.
 *
 *   COMPOSITE INDEX (user_id, revoked):
 *   - Optimizes the "revoke all active tokens for user" query:
 *     WHERE user_id = ? AND revoked = false
 *   - This query runs on every logout and on replay detection.
 *
 * CLEANUP STRATEGY:
 *   Run a scheduled command to prune expired/revoked tokens:
 *     DELETE FROM refresh_tokens WHERE revoked = true AND expires_at < NOW() - INTERVAL 7 DAY
 *   Keep revoked tokens for 7 days after expiry for forensic analysis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('token_hash', 64)
                ->unique()
                ->comment('SHA-256 hash of the refresh token.');

            $table->boolean('revoked')
                ->default(false)
                ->index();

            $table->timestamp('expires_at')
                ->index();

            $table->timestamp('created_at')
                ->useCurrent();

            // Composite index for "revoke all active tokens for user"
            $table->index(['user_id', 'revoked']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
    }
};