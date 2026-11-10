<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * employee_work_schedules — a person's schedule over a dated span, in the
 * pattern of employee_assignments (work shifts spec §3.1): spans never
 * overlap, at most one is open (to_date null), and a span starts today or
 * later, so a past day keeps the schedule it had. cycle_start_date is the
 * date that is position 0 of a cycle schedule. The employee FK restricts:
 * a schedule is part of the person's history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_work_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('work_schedule_id')->constrained('work_schedules')->restrictOnDelete();
            $table->date('from_date');
            $table->date('to_date')->nullable();
            $table->date('cycle_start_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'from_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_work_schedules');
    }
};
