<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * shift_swaps — a guard's request to exchange one of his duties for one of
 * another guard's (work shifts piece 2, spec §3.2), approved on the approvals
 * engine by his direct manager. Each duty is named by its start date. Once
 * approved, the exchange's effect is the shift_changes rows it wrote, which
 * now point back at it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_swaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('partner_id')->constrained('employees')->restrictOnDelete();
            $table->date('requester_duty_date');
            $table->date('partner_duty_date');
            $table->text('reason')->nullable();
            $table->unsignedTinyInteger('status');
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['requester_id', 'requester_duty_date']);
            $table->index(['partner_id', 'partner_duty_date']);
        });

        Schema::table('shift_changes', function (Blueprint $table) {
            $table->foreign('shift_swap_id')->references('id')->on('shift_swaps')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shift_changes', function (Blueprint $table) {
            $table->dropForeign(['shift_swap_id']);
        });

        Schema::dropIfExists('shift_swaps');
    }
};
