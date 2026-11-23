<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The groups a template is filed under in the picker (documents design §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_template_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_template_categories');
    }
};
