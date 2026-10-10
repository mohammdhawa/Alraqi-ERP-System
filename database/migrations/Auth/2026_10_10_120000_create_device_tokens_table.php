<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create device_tokens — where a push notification goes (work order §4.3).
 *
 * Legacy kept one `users.fcm_token`, so a person's second device silently
 * replaced the first and only the last phone signed in ever got a push
 * (EMPLOYEE_APP_REQUIREMENTS.md §6.4). One row per device instead.
 *
 * `token` IS UNIQUE ACROSS USERS, not per user: an FCM token identifies an app
 * install, and when a phone changes hands the next person to sign in on it
 * takes the row over. Per-user uniqueness would let the previous owner keep
 * receiving the new owner's notifications.
 *
 * `session_id` is the session the token was registered from (see the
 * refresh_tokens session migration). When that session ends the row is
 * deleted, so a signed-out phone stops showing the person's notifications.
 * Nullable: a token registered by a request without a session (tests) has
 * none, and lives until the account's sessions all end.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token', 512)->unique();
            $table->string('platform', 16);
            $table->uuid('session_id')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
