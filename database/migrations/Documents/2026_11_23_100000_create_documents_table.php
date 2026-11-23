<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The documents (documents design §6.1). The columns the later pieces need
 * (templates, صادر, recipient, the employee's answer, legacy) exist now,
 * nullable. `status` is written only by the approval engine's callback for a
 * document between managers, and by `DocumentClosing`, which writes 7
 * (NotCompleted) directly in the same transaction as the engine's cancel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('kind');
            $table->unsignedTinyInteger('origin');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->foreignId('creator_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('creator_unit_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('register_unit_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedBigInteger('template_id')->nullable();
            $table->unsignedInteger('template_version')->nullable();
            $table->json('field_values')->nullable();
            $table->unsignedInteger('export_number')->nullable();
            $table->unsignedInteger('import_number')->nullable();
            // وارد is unique among LIVE documents of a register: one deleted
            // before its first signature, or closed as «لم يُستكمل» (status 7,
            // spec §7.8), is sent again with the same number. The column holds
            // the number only while the row is neither (NULLs never collide in
            // a unique index); never mass-assigned.
            $table->unsignedInteger('live_import_number')
                ->storedAs('CASE WHEN deleted_at IS NULL AND status <> 7 THEN import_number END')
                ->nullable();
            $table->string('reference', 64)->nullable()->unique();

            $table->unsignedTinyInteger('status');
            $table->foreignId('recipient_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->unsignedTinyInteger('filing_type')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('refused_at')->nullable();
            $table->text('refusal_reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            // The system administrator's closing as «لم يُستكمل» (spec §7.8).
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('closed_reason')->nullable();

            $table->uuid('folder');
            $table->string('original_path', 512)->nullable();
            $table->string('uploaded_path', 512)->nullable();
            $table->string('printed_path', 512)->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->json('legacy')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['register_unit_id', 'live_import_number']);
            $table->unique(['register_unit_id', 'export_number']);
            $table->index(['creator_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
