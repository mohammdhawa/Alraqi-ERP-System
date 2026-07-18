<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create notifications table.
 *
 * DESIGN DECISION (RATIFIED — erp-phase1-architecture.md §7.2, 2026-07-17):
 * this is a CUSTOM notifications table keyed by user_id — not Laravel's
 * built-in (UUID-keyed, polymorphic notifiable, JSON `data`) table. First-class
 * columns (title/body/type/is_read) plus a polymorphic `reference` let a
 * notification point at any domain record (an employee, a department, etc.).
 *
 * Per-user rows are the correct grain because department/role sends are
 * SNAPSHOT fan-outs: NotificationService expands the audience to individual
 * users at send time, so a recipient-side polymorphic target is never needed.
 * Consequence: `$user->notify()` / the stock database channel are deliberately
 * unused — all rows are created via NotificationService::sendTo*().
 *
 *   user_id (cascadeOnDelete): notifications belong to one user and are removed
 *   with them.
 *   reference_type + reference_id (nullableMorphs): optional link to the
 *   subject record, with a composite index for "notifications about X" lookups.
 *   is_read (indexed): unread-count and unread-list queries filter on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('type')->nullable();
            $table->nullableMorphs('reference'); // reference_type + reference_id
            $table->boolean('is_read')->default(false)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
