<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop two retired `employees` columns from databases that still have them.
 *
 *   legacy_number      — the old `M024333` series. One staff number, not two
 *                        (project owner, 2026-09-23).
 *   direct_manager_id  — a manager recorded outside the org tree. The company
 *                        has no such exception (START_HERE §9, decision 20).
 *
 * WHY A FORWARD MIGRATION, when both were already removed from
 * `2026_09_13_110000_restructure_employees_table`: that migration was edited in
 * place, on the reasoning that no real staff had been imported. That makes a
 * FRESH install correct and does nothing for a database where the original
 * version already RAN — Laravel never re-runs a migration it has recorded, so
 * those databases kept both columns, and `direct_manager_id` kept its FK,
 * while no code reads either of them any more. Every environment that migrated
 * before those edits (a developer's local MySQL, a staging copy) needs this.
 *
 * CONDITIONAL, so it is a no-op on a fresh install — where the edited
 * restructure migration never created either column — and a real drop
 * everywhere else. The FK is dropped before its column, as MySQL requires.
 *
 * `down()` does NOT put them back. The columns are retired by decision, the
 * restructure migration no longer creates them, and a rollback that recreated
 * them would reintroduce two columns nothing reads — the very drift this
 * migration removes. The retired values are not recoverable from here either:
 * take a backup first if an upgraded database holds any worth keeping.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('employees', 'direct_manager_id')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropForeign(['direct_manager_id']);
            });

            Schema::table('employees', function (Blueprint $table) {
                $table->dropColumn('direct_manager_id');
            });
        }

        if (Schema::hasColumn('employees', 'legacy_number')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropUnique(['legacy_number']);
            });

            Schema::table('employees', function (Blueprint $table) {
                $table->dropColumn('legacy_number');
            });
        }
    }

    public function down(): void
    {
        // Deliberately empty — see the class docblock.
    }
};
