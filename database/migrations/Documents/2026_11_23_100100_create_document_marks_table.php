<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What is printed on a document, in order (documents design §6.2). Rows are
 * never changed or deleted, so no soft delete: the printed copy is rebuilt
 * from the original and these rows. The stamp and signature columns name the
 * exact version printed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->unsignedTinyInteger('kind');
            $table->foreignId('approval_step_id')->nullable()->constrained('approval_steps')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('employee_name', 255);
            $table->foreignId('unit_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->string('unit_name', 255)->nullable();
            $table->foreignId('stamp_id')->nullable()->constrained('unit_stamps')->restrictOnDelete();
            $table->foreignId('signature_id')->constrained('employee_signatures')->restrictOnDelete();
            $table->boolean('decisive')->default(false);
            $table->string('note', 500)->nullable();
            $table->unsignedTinyInteger('confirmed_by');
            $table->timestamp('marked_at');
            $table->timestamps();

            $table->unique(['document_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_marks');
    }
};
