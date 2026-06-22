<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add is_active flag to users table.
 *
 * WHY a separate migration (not modifying the default users migration):
 * - Laravel ships with a default create_users_table migration.
 * - Module-specific additions should be separate migrations.
 * - This makes it clear which schema belongs to the Auth module.
 * - Rollback is clean: drop the column without touching the base table.
 *
 * The `is_active` field allows admins to disable accounts without deleting them.
 * Disabled accounts retain their data and audit trail but cannot authenticate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')
                ->default(true)
                ->after('password')
                ->index()
                ->comment('Whether the user account is active. Inactive users cannot log in.');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};