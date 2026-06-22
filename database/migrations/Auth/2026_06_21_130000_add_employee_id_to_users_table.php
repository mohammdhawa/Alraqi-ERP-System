<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link users to employees.
 *
 * A user account (identity / login) optionally maps to one HR employee record.
 * The FK lives on users so that authentication can resolve the employee profile
 * of the logged-in user, while employees can exist without a login (e.g. staff
 * who never sign in to the system).
 *
 *   users.employee_id -> employees.id (nullOnDelete): deleting the employee
 *   record detaches it from the user without destroying the login.
 *
 * Runs after create_employees_table so the referenced table exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('employee_id')
                ->nullable()
                ->after('id')
                ->constrained('employees')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->dropColumn('employee_id');
        });
    }
};
