<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An employee's drawn signature (documents design §6.3, §10.1). At most one
 * live row per employee, enforced by Signatures under a lock; a deleted one
 * is soft-deleted and kept with its dates (D15), because a printed mark
 * points at the exact version it printed (I.10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('path', 512);
            $table->unsignedTinyInteger('drawn_on');
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('delete_reason', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_signatures');
    }
};
