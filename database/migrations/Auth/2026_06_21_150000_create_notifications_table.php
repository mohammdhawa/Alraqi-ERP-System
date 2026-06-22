<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create notifications table.
 *
 * DESIGN DECISION: this is a CUSTOM notifications table matching the design
 * doc — not Laravel's built-in (UUID-keyed, JSON `data`) notifications table.
 * Keeping it custom gives us first-class columns (title/body/type/is_read) and
 * a polymorphic `reference` so a notification can point at any domain record
 * (an employee, a department, etc.).
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
