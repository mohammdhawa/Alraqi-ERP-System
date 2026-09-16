<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create employee_warnings — الإنذارات.
 *
 * The workbook tracks نوع الإنذار، تاريخ الإنذار، رقم الإنذار as THREE COLUMNS
 * on the employee row, which means an employee can hold exactly one warning,
 * forever — the second one overwrites the first, and the record of the first
 * is gone. Discipline is a sequence by nature: the whole point of a second
 * warning is that there was a first.
 *
 * So: rows.
 *
 * `sequence` is derived, not stored. "Is this their third warning?" is
 * `COUNT(*) WHERE employee_id = ? AND issued_on <= ?`, which stays correct when
 * a mis-filed warning is deleted or a back-dated one is added. The reference
 * schema stores it as a column; that column is wrong the first time anyone
 * deletes warning #2 of 3 (P1).
 *
 * SOFT DELETES (C23): a rescinded warning must remain visible to an audit —
 * "this was withdrawn" and "this never happened" are different claims, and only
 * one of them is usually true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_warnings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            // نوع الإنذار — free text rather than an enum: unlike the pay
            // criteria, the observed values are a description of what happened
            // rather than a fixed catalogue, and no rule branches on them. An
            // enum here would be a guess at a set nobody has ratified (P4 cuts
            // both ways — a set that really does change belongs in data).
            $table->string('type');

            $table->string('number')->nullable();   // رقم كتاب الإنذار
            $table->date('issued_on');

            // Who issued it — an employee, not a user: warnings are signed by a
            // manager, who may well have no login.
            $table->foreignId('issued_by')->nullable()->constrained('employees')->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The only query path: this person's warnings, most recent first.
            $table->index(['employee_id', 'issued_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_warnings');
    }
};
