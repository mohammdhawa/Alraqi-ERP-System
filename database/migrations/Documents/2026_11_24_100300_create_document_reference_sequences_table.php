<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The count behind a template reference, per prefix and year (documents
 * design §8.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_reference_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 10);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('current_value')->default(0);
            $table->timestamps();

            $table->unique(['prefix', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_reference_sequences');
    }
};
