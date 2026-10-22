<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * attendance_records — one row per check-in or check-out (work order §4.5;
 * LEGACY_TO_ERP_MAPPING.md §4; docs/ATTENDANCE_BUILD.md).
 *
 * AN EVENT LOG, AS LEGACY. The day's state — present, absent, on leave,
 * missing check-out — is derived from these rows. The one stored state is
 * `missing_checkout`, written on an orphan check-in by the auto-close job, so
 * `status` is null on every other row.
 *
 * ABOUT A PERSON, PRESSED BY AN ACCOUNT. `employee_id` is whose day this is;
 * `corrected_by` and `rejected_by` are the accounts that acted, because the
 * actor may be an account with no employee. The employee FK RESTRICTS: an
 * attendance row is a payroll input, never cascaded away.
 *
 * NO UNIT SNAPSHOT (D6, 2026-10-16). Legacy stamped the department and section
 * on each row; the ERP's scope follows the employee's CURRENT unit, and
 * employee_assignments answers "where was this person on day X".
 *
 * KEPT FOREVER (D7). Records are never deleted — legacy's monthly cleanup is
 * not ported. Only the selfie FILE expires, after three months;
 * `selfie_purged_at` says it was removed on purpose.
 *
 * `client_uuid` (D9) makes a retried submission idempotent: unique per
 * employee, nullable so a submission without one still lands. MySQL's unique
 * index allows any number of NULLs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();

            $table->unsignedTinyInteger('type');                  // AttendanceEventType
            $table->unsignedTinyInteger('status')->nullable();    // AttendanceRecordStatus — missing check-out only
            $table->unsignedTinyInteger('source');                // AttendanceSource

            // The moment the employee tapped, as the phone saw it, normalised to
            // the attendance timezone. created_at is when the server received
            // it; the gap is how an offline submission shows.
            $table->dateTime('recorded_at');

            // Written on the check-out row only: the day's hours.
            $table->decimal('work_hours', 5, 2)->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Private disk. Null on an HR-corrected check-out, which has no
            // selfie, and after the file is purged.
            $table->string('selfie_path')->nullable();
            $table->timestamp('selfie_purged_at')->nullable();

            $table->uuid('client_uuid')->nullable();

            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('corrected_at')->nullable();
            $table->string('correction_note', 500)->nullable();

            // A refusal keeps the row as evidence; every rule and report looks
            // straight through it. The batch is what lets an un-refusal restore
            // exactly the rows one decision took down (a check-in and the
            // check-out paired with it).
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('rejection_reason')->nullable();   // RejectionReason
            $table->text('rejection_note')->nullable();
            $table->uuid('rejection_batch')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['employee_id', 'client_uuid']);
            $table->index(['employee_id', 'recorded_at']);
            $table->index('recorded_at');
            $table->index(['employee_id', 'status']);
            $table->index(['employee_id', 'rejected_at']);
            $table->index('rejection_batch');
            $table->index('selfie_purged_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
