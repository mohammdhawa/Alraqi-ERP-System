<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * work_schedules — which shift falls on which day (work shifts spec §3.1).
 * Weekly (by weekday, cycle_days = 7) or a cycle of 1–28 days counted from
 * each person's cycle start. Its days are fixed once anyone is assigned it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->unsignedTinyInteger('kind');            // WorkScheduleKind
            $table->unsignedTinyInteger('cycle_days');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_schedules');
    }
};
