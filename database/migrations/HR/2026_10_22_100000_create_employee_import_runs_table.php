<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create employee_import_runs — one row per upload on the import screen
 * (EMPLOYEE_IMPORT_UPLOAD_DESIGN.md §4.1).
 *
 * A run is the dry run's verdict on two stored files, and then, at most once,
 * the real import of exactly those files (U5). It lives an hour: then the
 * files go and the report — names, numbers, pay values — is purged (§4.6).
 * What stays for good is who uploaded what, when, its hashes and its counts.
 *
 * No password is ever written here: the real run's temporary passwords go out
 * in its own response and nowhere else (U4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_import_runs', function (Blueprint $table) {
            $table->id();

            // Restrict: a run is history, and the account it names stays.
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            // ImportRunStatus: 1 checked, 2 executed, 3 expired.
            $table->unsignedTinyInteger('status')->index();

            // The names as HR chose them — data, never a path (the real
            // workbook's name starts with invisible right-to-left marks).
            $table->string('workbook_name');
            $table->string('sarh_name')->nullable();

            // Where the files wait, on the private disk; nulled when deleted.
            $table->string('workbook_path')->nullable();
            $table->string('sarh_path')->nullable();

            // Kept after the files are gone: they say which files a run read.
            $table->char('workbook_sha256', 64);
            $table->char('sarh_sha256', 64)->nullable();

            $table->json('summary');
            $table->json('report')->nullable();

            // dateTime, as attendance's recorded_at: MySQL refuses a second
            // NOT NULL `timestamp` column with no default.
            $table->dateTime('checked_at');
            $table->dateTime('executed_at')->nullable();
            $table->dateTime('expires_at')->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_import_runs');
    }
};
