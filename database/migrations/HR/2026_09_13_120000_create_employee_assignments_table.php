<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create employee_assignments — سجل التعيين، the posting history.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * THE PROBLEM THIS SOLVES
 * ───────────────────────────────────────────────────────────────────────────
 * The workbook carries three columns — مكان العمل بداية التعيين،
 * المدير المباشر بداية التعيين، المسمى الوظيفي بداية التعيين — holding the
 * posting a person had on their first day, frozen forever. They answer exactly
 * one question ("where did they start?") and cannot answer "where were they in
 * March?", which is the question payroll, attendance and any audit actually
 * ask. Meanwhile transfers live in a separate ملف النقل sheet that nothing
 * joins back.
 *
 * That is a history table trying to exist inside three columns and a second
 * spreadsheet. This is the table.
 *
 * The FIRST row of every employee's history is their initial posting, so the
 * three frozen columns become a query (`ORDER BY from_date LIMIT 1`) and the
 * transfer sheet becomes rows.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * WHY `employees.department_id` SURVIVES ALONGSIDE THIS
 * ───────────────────────────────────────────────────────────────────────────
 * It looks like a P1 violation — the current posting is derivable from the row
 * with `to_date IS NULL` — and it is kept anyway, deliberately, because that
 * pointer is on every hot query path (every employee list, every org filter,
 * every permission scope the ERP will eventually grow). Resolving it through a
 * subquery on every read is a real cost paid constantly to avoid a risk paid
 * rarely.
 *
 * The risk is then closed by structure rather than by discipline (P2): the two
 * are synchronised in MODEL HOOKS that every writer crosses, in both
 * directions —
 *
 *   EmployeeAssignment::saved  -> writes the pointer onto the employee
 *   Employee::saved            -> writes the pointer into the open assignment
 *
 * so no writer, including a seeder or tinker, can leave them disagreeing. See
 * those two models for how the recursion is broken.
 *
 * SOFT DELETES (C23): payroll will FK a payslip to the assignment it was
 * computed for, and a payslip whose posting has vanished cannot be explained.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_assignments', function (Blueprint $table) {
            $table->id();

            // Cascade: a posting has no meaning without its person, and
            // employees are themselves never hard-deleted, so this fires only
            // on a deliberate force-delete.
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            // The unit and title AT THE TIME. nullOnDelete rather than cascade:
            // a department being dissolved must not erase the fact that someone
            // worked in it.
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('job_title_id')->nullable()->constrained('job_titles')->nullOnDelete();

            // The السوية at the time — a promotion changes it, and last year's
            // payslip must still read last year's grade.
            $table->unsignedTinyInteger('organizational_grade')->nullable();

            $table->date('from_date');

            // NULL = this is the CURRENT posting. Exactly one row per employee
            // may hold NULL here; the model's saving hook enforces it, because
            // neither MySQL nor SQLite can express "unique among the rows where
            // this column is null" as an index.
            $table->date('to_date')->nullable();

            $table->unsignedTinyInteger('reason')->default(1);  // 1 = تعيين

            // Import-only. The workbook's مكان العمل and المدير المباشر are free
            // text that predates the org tree and the employee table, and some
            // of it will never resolve to a row. Keeping the raw string means an
            // unresolvable value is preserved for a human to read rather than
            // silently dropped or, worse, guessed at.
            $table->string('legacy_workplace')->nullable();
            $table->string('legacy_manager_name')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // "This employee's history, in order" and "this employee's current
            // posting" are the two query paths that exist (C26).
            $table->index(['employee_id', 'from_date']);
            $table->index(['employee_id', 'to_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_assignments');
    }
};
