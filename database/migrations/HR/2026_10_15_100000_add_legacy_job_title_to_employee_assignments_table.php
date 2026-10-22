<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The title an imported employee STARTED with, as text on their opening
 * posting (project owner, 2026-10-15 — option B; work order §8).
 *
 * The workbook gives the title someone started with (`المسمى الوظيفي بداية
 * التعيين`) and the one they hold now, but never the date it changed. Two
 * postings would need that date invented; one posting with the current title
 * and the starting one kept beside it as written does not. It is free text,
 * import-only, like legacy_workplace and legacy_manager_name: a value nobody
 * has to resolve into the job_titles catalogue to keep.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_assignments', function (Blueprint $table) {
            $table->string('legacy_job_title')->nullable()->after('legacy_manager_name');
        });
    }

    public function down(): void
    {
        Schema::table('employee_assignments', function (Blueprint $table) {
            $table->dropColumn('legacy_job_title');
        });
    }
};
