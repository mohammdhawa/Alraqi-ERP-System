<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add the deferred manager_id foreign key to departments.
 *
 * Step 3 (final) of the departments/employees chicken-and-egg chain. The
 * `manager_id` column was created without a constraint in
 * create_departments_table; now that the employees table exists, we close the
 * loop with the FK:
 *
 *   departments.manager_id -> employees.id (nullOnDelete): if the managing
 *   employee is deleted, the department is simply left without a manager
 *   rather than being removed.
 *
 * Timestamp sorts after create_employees_table so the referenced table is
 * guaranteed to exist when this runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->foreign('manager_id')
                ->references('id')
                ->on('employees')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign(['manager_id']);
        });
    }
};
