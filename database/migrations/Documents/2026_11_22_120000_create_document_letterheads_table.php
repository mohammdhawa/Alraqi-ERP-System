<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The company's letterhead (documents design §6.3, §8.2): one row, a header
 * and a footer image on the private disk. Not encrypted: it signs nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_letterheads', function (Blueprint $table) {
            $table->id();
            $table->string('header_path', 512)->nullable();
            $table->timestamp('header_uploaded_at')->nullable();
            $table->string('footer_path', 512)->nullable();
            $table->timestamp('footer_uploaded_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_letterheads');
    }
};
