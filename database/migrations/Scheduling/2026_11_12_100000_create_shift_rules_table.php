<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * shift_rules — a shift's attendance rules as dated versions (per-shift rules
 * spec §2.1). The version in force on a date is the latest whose
 * effective_from is on or before it, so editing the rules never changes a
 * past day. One version per shift per date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->date('effective_from');
            $table->unsignedSmallInteger('late_grace_minutes');         // 0–120
            $table->unsignedSmallInteger('early_leave_grace_minutes');  // 0–120
            $table->unsignedSmallInteger('check_in_opens_minutes');     // 0–240
            $table->unsignedSmallInteger('check_out_closes_minutes');   // 0–480
            $table->boolean('overtime_enabled')->default(false);
            $table->unsignedSmallInteger('overtime_min_minutes')->default(0); // 0–240
            $table->unsignedSmallInteger('required_minutes');           // 1 … the shift's length
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['shift_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_rules');
    }
};
