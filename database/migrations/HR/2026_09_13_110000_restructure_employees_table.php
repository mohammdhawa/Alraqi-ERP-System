<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restructure `employees` from the Phase 1 shape to the real company's shape.
 *
 * Phase 1 gave this table nine business columns, built from a design document.
 * The company's own artefacts — the org chart, the HR + payroll workbook, the
 * new-hire intake form, and the later full scan of the HR folder — show HR
 * tracks roughly fifty facts, three of which are classification axes that drive
 * pay. This migration is the correction (phase-1.5-foundation-truth-up.md §5).
 *
 * ⚠️ THIS IS THE LAST RELEASE IN WHICH THIS IS CHEAP. The database has never
 * held a real employee. After the Batch 4 import it is a data migration against
 * 465 rows on a system HR depends on.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * WHAT IS DROPPED, AND WHY
 * ───────────────────────────────────────────────────────────────────────────
 * `name`      -> first_name / middle_name / last_name. Storing the parts AND a
 *                joined string is two columns for one fact (P1), and the
 *                workbook proves the failure mode: it carries الإسم الثلاثي,
 *                الاسم القديم, *and* a مقارنة الأسماء column whose only job is
 *                to flag where the two disagree. That third column is a bug
 *                report about the data model. `full_name` is an accessor.
 *
 * `job_title` -> job_title_id, an FK into the governed catalogue.
 *
 * `salary`    -> dropped outright, NOT moved. For the large majority
 *                (salary_mode = scale) the number is not a stored fact at all:
 *                it is the output of the criteria calculation. Caching a
 *                derived value with no invalidation strategy means changing the
 *                company scale factor (النسبة المئوية للقيمة, currently 0.75)
 *                silently makes every row wrong (P1/C25). Only the إتفاق track
 *                has a real stored amount, and it lands in `agreed_salary`.
 *
 * `status`    -> re-created as an indexed unsignedTinyInteger backed by the
 *                EmployeeStatus enum. The SQL enum was recorded in
 *                API_BUILD_STANDARD.md §12 as a known C26 violation to fix
 *                "as part of that rebuild". This is that rebuild.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * WHAT IS DELIBERATELY *NOT* HERE
 * ───────────────────────────────────────────────────────────────────────────
 * `سنوات القدم` (seniority years) — it is `hire_date` and the payroll period.
 * Storing it needs a nightly job to keep hundreds of rows honest, and the day
 * that job fails every payslip is wrong (P1).
 *
 * `اللقب` (أبو فلان) — ratified decision #2: full name only.
 *
 * `مكان الراتب` — ratified decision #3: finance pays everyone, so there is no
 * payroll grouping to store.
 *
 * `currency` — ratified decision #4: all money in this ERP is USD, stated once
 * rather than repeated as a column on every row.
 *
 * الديانة / المذهب / السوابق الجنائية — collected by the intake form, and
 * deliberately not imported (project owner, 2026-09-13). No requirement reads
 * them: they drive no pay criterion, no assignment and no approval. Data that
 * is never stored is the only data that cannot leak.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * NULLABILITY, STATED ONCE
 * ───────────────────────────────────────────────────────────────────────────
 * Almost every new column is nullable even where the intake form makes it
 * mandatory (gender, for instance). That is not laxity — it is the import
 * requirement: §7.4 says the Batch 4 importer must REPORT the known data
 * problems (blank أسماء الإدارات, #N/A criteria, malformed شام كاش) rather than
 * silently absorb them, which means those rows must be able to land in order to
 * be reported on. A NOT NULL column would instead abort the import or, worse,
 * invite a fabricated default.
 *
 * Completeness is enforced at the front door instead: EmployeeRequest requires
 * gender on an API create. That is a legitimate split — "what HTTP callers must
 * supply" is a different question from "what the table can hold", and only the
 * second one binds the importer.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. The new columns ────────────────────────────────────────────
        Schema::table('employees', function (Blueprint $table) {
            // ---- Identity (§5.1) ----
            $table->string('first_name')->nullable()->after('employee_number');
            $table->string('middle_name')->nullable()->after('first_name');   // اسم الأب
            $table->string('last_name')->nullable()->after('middle_name');    // الكنية
            $table->string('mother_name')->nullable()->after('last_name');    // اسم الأم

            $table->unsignedTinyInteger('gender')->nullable()->after('mother_name');
            $table->date('birth_date')->nullable();
            $table->string('birth_place')->nullable();

            // Origin and residence are two different facts and the HR folder
            // records both. Backed by the Governorate enum rather than free
            // text — the source spreadsheets showed 22 distinct spellings of a
            // 14-member set (حماة / حماه).
            $table->unsignedTinyInteger('origin_governorate')->nullable()->index();
            $table->unsignedTinyInteger('residence_governorate')->nullable();

            $table->string('nationality')->default('سوري');
            $table->string('second_nationality')->nullable();

            $table->unsignedTinyInteger('marital_status')->nullable();
            $table->unsignedTinyInteger('wives_count')->default(0);

            // The intake form counts sons and daughters SEPARATELY
            // (عدد الأبناء الذكور / عدد الأبناء الاناث), so those are the stored
            // facts and `children_count` is their sum — an accessor on the
            // model, never a third column (P1).
            $table->unsignedTinyInteger('sons_count')->default(0);
            $table->unsignedTinyInteger('daughters_count')->default(0);

            $table->string('phone_secondary')->nullable()->after('phone');

            // الخدمة العسكرية — a routine, non-sensitive HR fact in Syria, and
            // one the company asks on every intake form.
            $table->boolean('did_military_service')->nullable();
            $table->date('military_discharge_date')->nullable();

            // ---- Education (§5.2) ----
            $table->unsignedTinyInteger('education_level')->nullable();
            $table->string('qualification')->nullable();     // free text: "كلية اقتصاد سنة 2"
            $table->string('specialization')->nullable();    // التخصص
            $table->boolean('is_graduated')->nullable();
            $table->date('graduation_date')->nullable();

            // ---- Classification — the axes that drive pay (§5.3) ----
            //
            // ⚠️ THE MOST IMPORTANT CORRECTNESS NOTE IN THIS SCHEMA:
            // `organizational_grade` (السوية التنظيمية) is a POSITION grade.
            // `departments.level` is a UNIT tier. Both are integers 1–5 and
            // they are NOT the same fact — a section head (السوية 3) can sit in
            // a division (level 2) row. Conflating them corrupts every payslip,
            // silently.
            $table->unsignedTinyInteger('activity_type')->nullable()->index();
            $table->unsignedTinyInteger('organizational_grade')->nullable()->index();
            $table->unsignedTinyInteger('experience_level')->nullable();
            $table->unsignedTinyInteger('engineering_rank')->nullable();
            $table->unsignedSmallInteger('industrial_experience_years')->nullable();

            // Four criteria that are flags rather than measurements.
            $table->boolean('has_hazard_pay')->default(false);      // الخطورة
            $table->boolean('has_scarcity_pay')->default(false);    // الندرة
            $table->boolean('has_dual_role_pay')->default(false);   // إزدواجية العمل
            $table->unsignedTinyInteger('distinction_grade')->nullable(); // التمييز بحسب التقييم

            // ---- Employment (§5.4) ----
            $table->unsignedTinyInteger('employment_type')->nullable();  // متعاقد / يحتاج تثبيت / جماعة / مثبت
            $table->date('termination_date')->nullable();
            $table->string('workplace')->nullable();   // مكان العمل — free text until Projects lands

            // The governed title, replacing the free string.
            $table->foreignId('job_title_id')->nullable()->constrained('job_titles')->nullOnDelete();

            // NULL means "resolve from the org tree" — the overwhelmingly common
            // case. The column exists ONLY to record the exception: someone who
            // reports outside their unit head. Storing every employee's manager
            // here would be a second copy of what departments.manager_id and the
            // tree already say (P1).
            $table->foreignId('direct_manager_id')->nullable()->constrained('employees')->nullOnDelete();

            // ---- Pay reference (§5.6) ----
            // Exactly 16 digits — the intake form constrains it, so this does
            // too (the length check is the guard's; the uniqueness is the
            // index's). Stored as a string: 16 digits overflows a signed INT,
            // and a leading zero is part of the number, not decoration.
            $table->string('sham_cash_account', 16)->nullable()->unique();

            $table->unsignedTinyInteger('salary_mode')->default(1);  // 1 = نظام رواتب
            $table->decimal('agreed_salary', 12, 2)->nullable();     // only when إتفاق
        });

        // ── 2. Drop the superseded columns ────────────────────────────────
        //
        // The index goes first: SQLite's native DROP COLUMN refuses to drop a
        // column an index still references, and MySQL cannot drop and re-add
        // one column name inside a single ALTER — which is why `status` is
        // re-created in its own statement below rather than here.
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['name', 'job_title', 'salary', 'status']);
        });

        // ── 3. `status`, re-created int-backed (C26) ──────────────────────
        Schema::table('employees', function (Blueprint $table) {
            $table->unsignedTinyInteger('status')->default(1)->index();  // 1 = نشط
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['job_title_id']);
            $table->dropForeign(['direct_manager_id']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn([
                'first_name', 'middle_name', 'last_name', 'mother_name',
                'gender', 'birth_date', 'birth_place', 'origin_governorate',
                'residence_governorate', 'nationality', 'second_nationality',
                'marital_status', 'wives_count', 'sons_count', 'daughters_count',
                'phone_secondary', 'did_military_service', 'military_discharge_date',
                'education_level', 'qualification', 'specialization', 'is_graduated',
                'graduation_date', 'activity_type', 'organizational_grade',
                'experience_level', 'engineering_rank', 'industrial_experience_years',
                'has_hazard_pay', 'has_scarcity_pay', 'has_dual_role_pay',
                'distinction_grade', 'employment_type', 'termination_date', 'workplace',
                'job_title_id', 'direct_manager_id', 'sham_cash_account',
                'salary_mode', 'agreed_salary', 'status',
            ]);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->string('name')->nullable();
            $table->string('job_title')->nullable();
            $table->decimal('salary', 12, 2)->default(0);
            $table->enum('status', ['active', 'inactive', 'terminated'])->default('active')->index();
        });
    }
};
