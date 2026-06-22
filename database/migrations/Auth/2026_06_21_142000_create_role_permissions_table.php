<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create role_permissions pivot table.
 *
 * Many-to-many join between roles and permissions. The composite primary key
 * (role_id, permission_id) both enforces uniqueness (a permission can't be
 * attached to the same role twice) and serves as the lookup index. Both FKs
 * cascade on delete so removing a role or permission cleans up its links.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();

            $table->primary(['role_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
