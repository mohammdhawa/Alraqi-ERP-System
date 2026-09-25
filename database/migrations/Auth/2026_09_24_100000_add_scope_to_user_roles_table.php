<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GAP-3 — a role grant carries a scope (START_HERE §9 decision 18).
 *
 * `user_roles` was a bare pivot with `primary(user_id, role_id)`. That key
 * forbids a legitimate grant — the same role scoped to two different units, a
 * supervisor over two sections — so it goes, and a surrogate `id` replaces it.
 * The scope columns cannot join the primary key instead: `scope_department_id`
 * is nullable, and a nullable column in a primary key is a trap.
 *
 * ⚙️ EVERY EXISTING GRANT BECOMES A COMPANY GRANT. That is exactly the reach it
 * had yesterday — before this, every role read every row — so nobody gains or
 * loses access by deploying this migration. Narrowing a grant is a deliberate
 * act, done afterwards through `/api/auth/roles/assign`.
 *
 * WHY REBUILD RATHER THAN ALTER: dropping a composite primary key and adding an
 * auto-increment id in place is a different dance on MySQL and SQLite, and
 * SQLite cannot drop a primary key at all. The rows are copied to a plain
 * backup table FIRST, so if anything between the drop and the re-insert fails
 * on MySQL (where DDL auto-commits), the grants are still on disk.
 *
 * `scope_department_id` restricts on delete: departments are only ever
 * soft-deleted in this system, and a hard delete that would silently turn a
 * unit grant into a malformed row is exactly what should fail loudly.
 */
return new class extends Migration
{
    /**
     * `ScopeType::Company`, written as a literal on purpose: a migration
     * describes the schema as it was on the day it ran, and must not change
     * meaning if the enum is ever renumbered.
     */
    private const COMPANY = 1;

    public function up(): void
    {
        $this->backup(['user_id', 'role_id']);

        Schema::drop('user_roles');

        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->unsignedTinyInteger('scope_type')->default(self::COMPANY);
            $table->foreignId('scope_department_id')->nullable()->constrained('departments')->restrictOnDelete();

            $table->unique(['user_id', 'role_id', 'scope_type', 'scope_department_id'], 'user_roles_grant_unique');
        });

        DB::table('user_roles')->insertUsing(
            ['user_id', 'role_id', 'scope_type'],
            DB::table('user_roles_backup')->select(['user_id', 'role_id', DB::raw(self::COMPANY)]),
        );

        Schema::drop('user_roles_backup');
    }

    /**
     * Collapses every grant back to one row per (user, role). Scopes are lost —
     * the old table has nowhere to keep them — and every surviving grant reads
     * as company-wide again, which is what the old schema meant.
     */
    public function down(): void
    {
        $this->backup(['user_id', 'role_id'], distinct: true);

        Schema::drop('user_roles');

        Schema::create('user_roles', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();

            $table->primary(['user_id', 'role_id']);
        });

        DB::table('user_roles')->insertUsing(
            ['user_id', 'role_id'],
            DB::table('user_roles_backup')->select(['user_id', 'role_id']),
        );

        Schema::drop('user_roles_backup');
    }

    /**
     * Copy the grant columns into a constraint-free table that survives the drop.
     *
     * @param  array<int, string>  $columns
     */
    private function backup(array $columns, bool $distinct = false): void
    {
        // A backup left behind means an earlier run died between the drop and
        // the re-insert, and that table may be the ONLY copy of the grants.
        // Dropping it to start over would destroy them; stop and let a human
        // restore from it instead.
        if (Schema::hasTable('user_roles_backup')) {
            throw new RuntimeException('user_roles_backup exists from an interrupted run; restore user_roles from it before migrating again.');
        }

        Schema::create('user_roles_backup', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
        });

        $source = DB::table('user_roles')->select($columns);

        DB::table('user_roles_backup')->insertUsing($columns, $distinct ? $source->distinct() : $source);
    }
};
