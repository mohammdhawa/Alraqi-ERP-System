<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A document template: its fields and its page (documents design §6).
 *
 * `version` rises on every edit that changes what a document would print, so
 * a document records which page it was made from. `legacy_id` lets the import
 * map a legacy template once. `documents.template_id` has no foreign key
 * (piece 2): a template is soft-deleted, and a document keeps its number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('document_template_categories')->restrictOnDelete();
            $table->string('name', 150);
            $table->string('description', 1000)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('for_employees')->default(false);
            $table->string('reference_prefix', 10);
            $table->json('fields');
            $table->longText('body')->nullable();
            $table->string('layout_key', 50)->nullable();
            $table->boolean('show_letterhead')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_templates');
    }
};
