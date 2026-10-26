<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the صرح file from employee_import_runs: HR merges its columns into
 * their own file, and the import reads that one file only (project owner,
 * 2026-10-24). A run is now the dry run's verdict on one stored file.
 *
 * Checked before this ran: no run still held a stored صرح file, so dropping
 * sarh_path orphans nothing on disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_import_runs', function (Blueprint $table) {
            $table->dropColumn(['sarh_name', 'sarh_path', 'sarh_sha256']);
        });
    }

    public function down(): void
    {
        Schema::table('employee_import_runs', function (Blueprint $table) {
            $table->string('sarh_name')->nullable()->after('workbook_name');
            $table->string('sarh_path')->nullable()->after('workbook_path');
            $table->char('sarh_sha256', 64)->nullable()->after('workbook_sha256');
        });
    }
};
