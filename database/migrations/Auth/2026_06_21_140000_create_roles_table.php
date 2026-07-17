<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create roles table.
 *
 * A role is a named bundle of permissions (e.g. "super_admin", "hr-manager").
 * Users are granted roles; roles carry permissions. This is the R in RBAC.
 *
 *   name       machine identifier, referenced in code/seeders (e.g. super_admin).
 *   label      human-facing display name, Arabic (e.g. "مدير النظام").
 *   is_system  a built-in role the application depends on (e.g. super_admin).
 *              System roles cannot be renamed or deleted through the API — the
 *              RoleService refuses to mutate them — so the RBAC bootstrap can
 *              never be edited out from under the app. Ordinary roles default to
 *              false and are fully editable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('label')->nullable();
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
