<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * shift_changes — a person's shift on one date, overriding their schedule
 * (work shifts piece 2, spec §3.1): a shift, or null for a day off. Written
 * by HR (origin 1) or by an approved duty exchange (origin 2, with its
 * shift_swap_id). One row per person per date. No soft delete: removing a
 * change deletes the row, and the audit log keeps it. The FK on
 * shift_swap_id arrives with shift_swaps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('date');
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->restrictOnDelete();
            $table->unsignedTinyInteger('origin');
            $table->unsignedBigInteger('shift_swap_id')->nullable()->index();
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_changes');
    }
};
