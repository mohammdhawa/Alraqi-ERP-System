<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create departments table.
 *
 * CHICKEN-AND-EGG WITH employees:
 *   - departments.manager_id references employees.id
 *   - employees.department_id references departments.id
 *
 * Two tables that each carry an FK to the other cannot both declare their
 * constraint at creation time. The agreed resolution (see architecture
 * report §16.7) splits the dependency across three ordered migrations:
 *
 *   1. THIS migration            create `departments` WITHOUT the manager FK.
 *                                The `manager_id` column exists (nullable) so
 *                                the Department model is complete, but no FK
 *                                constraint is declared yet.
 *   2. create_employees_table    (Employees module) create `employees` with
 *                                its department_id FK -> departments.
 *   3. add_manager_fk_to_departments  (Employees module) add the FK
 *                                departments.manager_id -> employees.id.
 *
 * Timestamps must keep that order: this file (..._100000_) sorts before the
 * employees table and the FK migration that the Employees module adds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();

            $table->string('name');

            // FK constraint to employees is added later (see class docblock).
            // Declared as a nullable foreign-id column now so the schema and
            // the Department::manager() relation are ready, without depending
            // on the not-yet-existent employees table.
            $table->foreignId('manager_id')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
