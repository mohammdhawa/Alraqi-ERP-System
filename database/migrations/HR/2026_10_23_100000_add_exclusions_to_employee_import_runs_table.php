<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Excluding rows before the real import (EMPLOYEE_IMPORT_UPLOAD_DESIGN.md §12).
 *
 * - importable_rows: the sheet rows a dry run with nothing excluded would
 *   import (created, updated, restored) — set at the check, refreshed at each
 *   re-check. The screen offers checkboxes on these; the report cannot say,
 *   since it shows excluded rows as `excluded`.
 * - excluded_rows: the rows HR left out of this import. This upload only (U8).
 *
 * Row numbers name no one, so both are kept after expiry, like `summary`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_import_runs', function (Blueprint $table) {
            $table->json('importable_rows')->nullable()->after('report');
            $table->json('excluded_rows')->nullable()->after('importable_rows');
        });
    }

    public function down(): void
    {
        Schema::table('employee_import_runs', function (Blueprint $table) {
            $table->dropColumn(['importable_rows', 'excluded_rows']);
        });
    }
};
