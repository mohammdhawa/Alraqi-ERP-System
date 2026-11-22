<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A unit's official stamp, every version (documents design §6.3, §10.2). The
 * current one has `replaced_at` null. A version is never deleted, so no soft
 * delete: a printed card points at the exact version it printed (I.10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_stamps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->string('path', 512);
            $table->string('mime_type', 32);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('replaced_at')->nullable();
            $table->timestamps();

            $table->index(['department_id', 'replaced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_stamps');
    }
};
