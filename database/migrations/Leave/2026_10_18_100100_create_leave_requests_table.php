<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leave requests and HR excuses (work order §4.7; LEAVE_BUILD.md §4; mapping §5).
 *
 * TWO SNAPSHOTS, BOTH DELIBERATE. `deducts_balance` is the type's policy when
 * the leave was filed — legacy's argument: re-deciding a type later must never
 * hand people days back or take them. `requested_days` is the working days at
 * filing; the calendar may change afterwards.
 *
 * NO APPROVER COLUMN. Legacy mirrored "the current approver" in `manager_id`;
 * here the approval's steps hold it — one fact, one place (P1). NO UNIT
 * SNAPSHOT: visibility follows the employee's current unit (D6). NO FREE-TEXT
 * TYPE: nothing is imported, and new clients send an id.
 *
 * The employee FK restricts: a leave is part of a person's history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $table->unsignedTinyInteger('origin');
            $table->boolean('deducts_balance');
            $table->text('reason')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('requested_days');
            $table->unsignedTinyInteger('status');
            $table->timestamp('decided_at')->nullable();

            // HR's excuses record who filed them.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();

            // Return to work (legacy, 2026-09-21): written once, so a leave
            // shortened twice keeps the span that was granted.
            $table->date('returned_to_work_on')->nullable();
            $table->date('original_end_date')->nullable();
            $table->timestamp('shortened_at')->nullable();
            $table->foreignId('shortened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('shortening_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The balance sums, the overlap guard and the own list all read
            // one person's rows by status and date.
            $table->index(['employee_id', 'status', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
