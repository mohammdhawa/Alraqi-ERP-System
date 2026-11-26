<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The صادر register's counter, one row per إدارة (documents design §8.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->unique()->constrained('departments')->restrictOnDelete();
            $table->unsignedInteger('current_value')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_counters');
    }
};
