<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create departments table.
 *
 * ORGANIZATIONAL HIERARCHY (single self-referencing table):
 *
 *   الإدارة العامة (GeneralAdministration, level 1) -> إدارة (Division, level 2)
 *     -> قسم (Section, level 3) -> [future tiers…]
 *
 * Every tier is a row in THIS table; `parent_id` links a row to the row above
 * it and `level` says which tier it is. "Department" here is the superordinate
 * term for an organizational unit at ANY tier — it is not the Arabic "الإدارة
 * العامة" (level 1) specifically. Employees are NOT nodes in this tree: they
 * live in `employees` and point back via employees.department_id.
 *
 * WHY parent_id is declared HERE and not in a follow-up migration (unlike
 * manager_id): a self-referencing FK is valid at creation time because the
 * table exists the moment it is created. Laravel emits the constraint as a
 * separate ALTER TABLE after the CREATE, so `departments` is already there.
 * The manager_id chicken-and-egg below is a genuinely different problem — it
 * points at another table that does not exist yet.
 *
 * WHY `level` is an int and not an ENUM('division','section') column:
 * the tier NAME lives in the PHP enum App\Modules\Departments\Enums\
 * DepartmentLevel, which is backed by these same ints. Adding a future tier
 * (Branch = 4) is then a one-line enum change with NO migration and NO schema
 * change. A SQL enum would force an ALTER on every new tier, and a second
 * type/tier column alongside `level` would be two columns describing one fact
 * — a data-integrity bug waiting to happen. `level` is the single source of
 * truth; the PHP enum is a lens over it.
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

            // Short human-facing code for the unit (e.g. "HR", "FIN-01"). Optional
            // and unique when present — a nullable UNIQUE index allows many NULLs
            // (units without a code) while forbidding two units sharing one.
            $table->string('code')->nullable()->unique();

            // Free-text description of the unit's remit. Optional.
            $table->text('description')->nullable();

            // Whether the unit is currently active. Deactivating (is_active=false)
            // retires a unit from selection lists without deleting it or its
            // subtree. Defaults to true so existing writers need not set it.
            $table->boolean('is_active')->default(true);

            // The unit this one sits under. NULL = the root, which by the
            // hierarchy invariants (DepartmentHierarchyGuard, enforced both in
            // the form requests and in the Department model's saving hook) can
            // only ever be the single الإدارة العامة (level 1). At most one row
            // may be parentless — the singleton-root rule, which MySQL cannot
            // express, so it lives in the application layer.
            //
            // restrictOnDelete is a LAST-RESORT net for the hard-delete path
            // only. This table soft-deletes, and a soft delete is an UPDATE —
            // the FK never fires for it. The real guard against orphaning a
            // subtree is the `deleting` hook on the Department model.
            //
            // Indexed: "children of parent X" is the hot query for building
            // the tree. The index is declared before the constraint so InnoDB
            // adopts it for the FK instead of auto-creating a duplicate.
            $table->foreignId('parent_id')
                ->nullable()
                ->index()
                ->constrained('departments')
                ->restrictOnDelete();

            // Authoritative tier marker: 1 = الإدارة العامة (general
            // administration), 2 = division (إدارة), 3 = section (قسم).
            // Business logic reads THIS, never `parent_id IS NULL` — the root
            // is the general administration because level says so, not because
            // it happens to lack a parent. Must map to a DepartmentLevel case;
            // validation rejects levels the enum does not define.
            //
            // NO default: `level` is the single source of truth for a unit's
            // tier, and 1 now means "the company's root node" (الإدارة العامة).
            // A column that silently defaults a forgotten tier to the singleton
            // root is actively dangerous — it is exactly what let the seeder
            // create four roots without erroring. Every writer must state the
            // tier explicitly; the column stays NOT NULL with no default.
            //
            // Indexed: listing a whole tier ("all divisions" = level 2) is a
            // frequent query.
            $table->unsignedTinyInteger('level')->index();

            // FK constraint to employees is added later (see class docblock).
            // Declared as a nullable foreign-id column now so the schema and
            // the Department::manager() relation are ready, without depending
            // on the not-yet-existent employees table.
            //
            // One column serves every tier: a division manager and a section
            // head are the same fact at different levels.
            $table->foreignId('manager_id')->nullable();

            $table->timestamps();

            // Rows are never hard-deleted in this ERP: a department is FK'd to
            // by employees (and by later modules), and its audit trail must
            // stay resolvable.
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
