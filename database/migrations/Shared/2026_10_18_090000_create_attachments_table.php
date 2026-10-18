<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files on a record (LEAVE_BUILD.md §5) — a leave's medical report now, a
 * document's pages in November. ONE table for both, on purpose: the work order
 * (§4.7) asks for a shared one rather than a leave-only table replaced a month
 * later.
 *
 * The subject is a morph alias (`leave_request`), never a class name. The file
 * itself lives on the PRIVATE disk under `path` — a uuid the server chose,
 * never the client's name — and is only ever streamed through an endpoint that
 * asks the subject's policy (mapping finding 5). `original_name` is kept to
 * offer the download under the name the person knows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->string('attachable_type', 64);
            $table->unsignedBigInteger('attachable_id');
            $table->string('disk', 32);
            $table->string('path', 512);
            $table->string('original_name', 255);
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['attachable_type', 'attachable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
