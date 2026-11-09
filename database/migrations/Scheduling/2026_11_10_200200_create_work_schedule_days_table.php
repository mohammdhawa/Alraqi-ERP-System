<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * work_schedule_days — one row per position of a schedule, every position
 * stated: a null shift is a day off, never implied (work shifts spec §3.1).
 * Weekly positions are Carbon's dayOfWeek (0 = Sunday … 6 = Saturday).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_schedule_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_schedule_id')->constrained('work_schedules')->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['work_schedule_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_schedule_days');
    }
};
