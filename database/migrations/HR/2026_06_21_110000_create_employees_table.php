<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create employees table.
 *
 * Step 2 of the departments/employees chicken-and-egg chain (see the
 * create_departments_table migration). Runs AFTER departments exists so the
 * department_id FK can be declared here, and BEFORE add_manager_fk_to_departments
 * so that migration can point departments.manager_id at this table.
 *
 *   department_id -> departments.id (nullOnDelete): an employee keeps existing
 *   if their department is removed; the link is simply nulled.
 *
 *   status is indexed because listing/filtering active staff is the common
 *   query path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();

            $table->foreignId('department_id')
                ->nullable()
                ->constrained('departments')
                ->nullOnDelete();

            $table->string('job_title')->nullable();
            $table->date('hire_date')->nullable();
            $table->decimal('salary', 12, 2)->default(0);

            $table->enum('status', ['active', 'inactive', 'terminated'])
                ->default('active')
                ->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
