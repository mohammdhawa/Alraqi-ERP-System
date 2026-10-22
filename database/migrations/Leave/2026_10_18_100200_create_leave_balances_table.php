<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One employee's allocation for one year, set by HR (LEAVE_BUILD.md §3) —
 * legacy's leave_balances on employees.
 *
 * No row means `config('leave.annual_days')` applies. The adjustment stays
 * apart from the allocation, as legacy argues: a carry-over or a penalty is a
 * different fact from the entitlement — and it is where HR enters each
 * person's opening balance at their switch date (Q6).
 *
 * The balance itself is never stored: it is computed from approved leave.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedSmallInteger('allocated_days');
            $table->smallInteger('adjustment_days')->default(0);
            $table->string('note', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_balances');
    }
};
