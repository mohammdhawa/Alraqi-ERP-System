<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Work shifts, step 1 (docs/superpowers/specs/2026-11-09-work-shifts-backend-core-design.md §3.2).
 *
 * `shift_date` — the date of the shift a record belongs to: its duty's start
 * date. Recorded when the event is taken, never re-derived, so a schedule
 * change cannot move a past record. Every existing row is backfilled with
 * DATE(recorded_at), which is exactly how records were grouped until now
 * (recorded_at is stored in the attendance timezone).
 *
 * `outside_schedule` — no shift's check-in window fitted the check-in: it was
 * accepted and flagged (decision round S10).
 *
 * `checkout_reminded_at` — on a check-in, when its check-out reminder was
 * sent, so the shift clock never sends it twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->date('shift_date')->nullable()->after('recorded_at');
            $table->boolean('outside_schedule')->default(false)->after('shift_date');
            $table->timestamp('checkout_reminded_at')->nullable()->after('outside_schedule');
        });

        DB::table('attendance_records')->update(['shift_date' => DB::raw('DATE(recorded_at)')]);

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->date('shift_date')->nullable(false)->change();
            $table->index(['employee_id', 'shift_date']);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropIndex(['employee_id', 'shift_date']);
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn(['shift_date', 'outside_schedule', 'checkout_reminded_at']);
        });
    }
};
