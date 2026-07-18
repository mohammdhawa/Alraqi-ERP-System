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
 *
 * HISTORICAL RETENTION: employees are NEVER hard-deleted (softDeletes). A person
 * is FK'd to by users (employee_id), departments (manager_id), and later modules
 * (payroll, attendance, projects); their identity and audit trail must stay
 * resolvable after they leave. Deletion sets deleted_at; the row survives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();

            // Stable staff identifier, unique across the company. Auto-generated
            // (EMP-00001, …) by the Employee model's creating hook when omitted,
            // so every write path — API, seeder, factory, tinker — gets one;
            // a caller may still supply an externally-assigned number.
            $table->string('employee_number')->unique();

            $table->string('name');

            // National / government ID. Nullable (not always on file), but unique
            // when present so the same person cannot be enrolled twice. A nullable
            // UNIQUE index allows many NULLs while forbidding duplicate real IDs.
            $table->string('national_id')->nullable()->unique();

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

            // Never hard-deleted (see class docblock): keeps the person's history
            // and every FK pointing at them resolvable.
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
