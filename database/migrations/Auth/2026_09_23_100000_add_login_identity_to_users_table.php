<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The account model ratified 2026-09-22 (START_HERE.md §9).
 *
 * WHY THIS RUNS BEFORE THE IMPORT, NOT AFTER: attendance is recorded through
 * the company app, so every one of the 242 active employees needs an account.
 * `users.email` was `unique NOT NULL`, and a large share of site staff have no
 * email address at all — so as it stood, the import could not have created the
 * accounts the attendance module depends on. The window for changing this
 * cheaply is open exactly until the first account exists.
 *
 * THREE CHANGES, AND ONE DELIBERATE NON-CHANGE:
 *
 * 1. `email` becomes NULLABLE. It stays unique and stays useful — it is now a
 *    CONTACT field rather than an identity. Repeated NULLs are permitted in a
 *    unique index on both MySQL and the SQLite test driver, so no partial index
 *    is needed and no sentinel value has to be invented.
 *
 * 2. `must_change_password` — the first password is issued by HR, so it is a
 *    credential somebody else has seen. The flag is what lets the API refuse
 *    every other route until it has been replaced (EnsurePasswordIsChanged).
 *
 * 3. `password_changed_at` — the audit question "when did this account last
 *    rotate its credential" has no other answer; `updated_at` moves for any
 *    change at all.
 *
 * ⚠️ NO `employee_number` COLUMN IS ADDED HERE, and that is the point.
 * The login identifier is the employee number, but the number is a fact about
 * the PERSON, not about the account — and `users.employee_id` is already
 * `unique`, so the account is reachable from the employee in one hop. Copying
 * the number onto `users` would be two columns for one fact (P1), and it would
 * need a synchronisation hook the day someone exercises the manual override
 * that decision 9 explicitly permits. AuthService resolves the login through
 * the relation instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();

            $table->boolean('must_change_password')->default(false)->after('password');
            $table->timestamp('password_changed_at')->nullable()->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['must_change_password', 'password_changed_at']);

            // Reversing the nullability is only safe while no row relies on it.
            // That is true by construction here: this migration exists so that
            // accounts CAN be created without an email, and it ships before the
            // first one is.
            $table->string('email')->nullable(false)->change();
        });
    }
};
