<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create job_titles — the governed catalogue of المسميات الوظيفية.
 *
 * Replaces the free string `employees.job_title`. The company does not invent
 * titles per hire: it keeps a catalogue (197 titles in the new-hire intake
 * form's q4, and the workbook's دليل الهيكل والمسميات الوظيفية sheet carries the
 * same list with a تم التوصيف flag — the output of قسم التحليل والتوصيف).
 *
 * ⚙️ NO `department_id`, BY RATIFIED DECISION (2026-09-13). A title is a
 * company-wide fact: محاسب is ONE row with one job description, whichever
 * divisions happen to employ accountants. `name` is therefore unique on its own.
 *
 *   "Which divisions use this title?" is
 *     SELECT DISTINCT department_id FROM employees WHERE job_title_id = ?
 *   — derived from real postings, so it cannot drift out of step with them. A
 *   scalar `department_id` here would be NULL for precisely the shared titles
 *   the question was about, and non-NULL only by the accident of a title
 *   currently having one employer (P1).
 *
 *   The known limit and its escape hatch: that query only knows divisions where
 *   someone is ALREADY posted, so it cannot express "offerable in إدارة
 *   الإمداد, which has not hired one yet". Nothing needs that today. If a
 *   division-filtered title picker is ever built, add a `job_title_department`
 *   pivot — do NOT re-add the scalar column.
 *
 * ⚠️ `unique` AND `softDeletes` INTERACT, and the FormRequest is where that is
 * paid for. The unique index counts trashed rows, so a deleted title's name is
 * not reusable until the row is force-deleted — the same trade-off
 * `users.email` carries, taken for the same reason. JobTitleRequest therefore
 * validates against ALL rows including trashed: a
 * `Rule::unique(...)->whereNull('deleted_at')` here would let validation pass
 * and then hand the user a 500 from the index where they should have had a 422
 * (C24 read in the correct direction).
 *
 * SOFT DELETES (C23): `employees.job_title_id` and
 * `employee_assignments.job_title_id` both FK here, and a posting history that
 * cannot resolve the title someone held is not a history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_titles', function (Blueprint $table) {
            $table->id();

            // المسمى الوظيفي — one shared, company-wide catalogue entry.
            $table->string('name')->unique();

            // The السوية التنظيمية and نوع النشاط this title normally carries.
            // SUGGESTIONS, not constraints: they pre-fill the form when a title
            // is picked, and the employee's own columns remain the fact. A
            // section head and a division head can hold the same title at
            // different grades, so the title cannot own the grade (P1).
            $table->unsignedTinyInteger('default_organizational_grade')->nullable();
            $table->unsignedTinyInteger('default_activity_type')->nullable();

            // تم التوصيف — whether قسم التحليل والتوصيف has written the job
            // description yet. Indexed because "which titles still need
            // describing" is that department's actual worklist (C26).
            $table->boolean('is_described')->default(false)->index();

            // بطاقة التوصيف الوظيفي — the description itself, once written.
            // One TEXT column rather than the reference schema's seven
            // (summary / qualifications / skills / authorities / duties / …)
            // because nothing in this phase reads the parts separately, and
            // columns nothing queries are columns nobody fills in correctly.
            // Splitting them later is a migration; un-splitting is a data loss.
            $table->text('description')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_titles');
    }
};
