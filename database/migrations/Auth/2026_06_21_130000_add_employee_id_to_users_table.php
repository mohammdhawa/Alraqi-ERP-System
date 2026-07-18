<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link users to employees.
 *
 * A user account (identity / login) optionally maps to one HR employee record,
 * and this link is now the SOLE source of the user's display name (users has no
 * `name` column). The FK lives on users so that authentication can resolve the
 * employee profile of the logged-in user, while employees can exist without a
 * login (e.g. staff who never sign in to the system).
 *
 *   users.employee_id -> employees.id (nullOnDelete): deleting the employee
 *   record detaches it from the user without destroying the login.
 *
 * UNIQUE: at most one account per employee. A nullable UNIQUE index permits many
 * rows with NULL (unlinked/service accounts) while forbidding two accounts from
 * claiming the same employee — the "one employee, one login" invariant the
 * schema can express directly. The create-user API additionally REQUIRES an
 * employee_id at the front door, so API-created accounts are always linked.
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
                ->unique()
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
