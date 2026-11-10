<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * shifts — a start time and a length (work shifts spec §3.1). A shift past
 * midnight ends the next day. Once a schedule uses a shift its start and
 * length are fixed (the model refuses the change): a new time is a new shift.
 * Codes stay unique across soft-deleted rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('starts_at', 5);                      // "H:i"
            $table->unsignedSmallInteger('duration_minutes');    // 1–1440
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
