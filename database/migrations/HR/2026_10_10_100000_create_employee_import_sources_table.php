<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create employee_import_sources — the importer's staging table (decision 11).
 *
 * ───────────────────────────────────────────────────────────────────────────
 * THE PROBLEM THIS SOLVES
 * ───────────────────────────────────────────────────────────────────────────
 * The Batch 3 importer is run several times against HR's corrections before
 * the real cut. It needs a key that survives between runs, or run two
 * duplicates run one. `legacy_number` was that key until it was dropped
 * (decision 10: no legacy identifier in the domain schema), and neither
 * `employee_number` (newly generated) nor `national_id` (nullable, and absent
 * from the October workbook entirely) can stand in.
 *
 * So the key lives here, beside the domain rather than in it: one row per
 * person-as-the-source-identifies-them, pointing at the employee it became.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * WHY `source_key` IS A STRING THE IMPORTER DERIVES
 * ───────────────────────────────────────────────────────────────────────────
 * No one source column identifies everybody: the October files carry
 * `ID_Sarah` on the active sheets but not on إنهاء عمل, and the old staff
 * number on about half the rows. The importer therefore chooses the key per
 * sheet and namespaces it (`id_sarah:582974643`), and this table only promises
 * that a key names one employee. Changing how keys are derived is an importer
 * change, not a migration.
 *
 * NOT unique on employee_id: a rehire appears once in إنهاء عمل and again in
 * قاعدة البيانات, and both rows are the same person.
 *
 * The source_* columns record where the key was last read, so the table can be
 * shown to HR as it stands: this row of this sheet became this person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_import_sources', function (Blueprint $table) {
            $table->id();

            $table->string('source_key', 191)->unique();

            // Cascade: employees are never hard-deleted, so this fires only on
            // a deliberate force-delete — after which a re-run creating the
            // person again is the correct outcome.
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->string('source_file')->nullable();
            $table->string('source_sheet')->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->string('source_name')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_import_sources');
    }
};
