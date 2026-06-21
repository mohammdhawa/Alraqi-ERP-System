<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create permissions table.
 *
 * A permission is a single, fine-grained capability named by the convention
 * {module}.{resource}.{action}, e.g. "hr.employees.view". The `module` column
 * is stored separately so permissions can be grouped/listed per module in
 * admin UIs without parsing the name string.
 *
 * These names are exactly the strings passed to the `permission:` middleware
 * on routes — the CheckPermission middleware looks them up by `name`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('module')->index();
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
