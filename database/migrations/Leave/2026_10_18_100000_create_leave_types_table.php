<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The leave-type catalogue (work order §4.7; LEAVE_BUILD.md §3; mapping §5).
 *
 * A TABLE, NOT A STRING, for legacy's reason: a type carries whether taking
 * it consumes the annual balance, and a request snapshots that flag when it
 * is filed, so editing a type here never rewrites leave already taken.
 *
 * SEEDED HERE, as legacy seeds its types in the migration, so every
 * environment has them without a seeder run. The nine are what legacy's
 * LIVE table holds (Q3, 2026-10-16), not the six its code seeds, with their
 * live flags — except that sick leave does NOT deduct (the project owner
 * overruled the live table) and requires the medical report. Legacy's
 * `attendent_execused` is spelt `attendance_excused`; its Arabic name is kept.
 *
 * No `sort_order`: the order is the id, legacy's argument — insertion order
 * already puts the vocabulary in HR's order and lands a new type last.
 *
 * Names and codes stay unique across soft-deleted rows: a retired type keeps
 * its code, so it can never be confused with a new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name_ar', 100)->unique();
            $table->string('name_en', 100)->nullable()->unique();
            $table->boolean('deducts_balance');
            $table->boolean('for_requests')->default(true);
            $table->boolean('for_excuses')->default(true);
            $table->boolean('requires_reason')->default(false);
            $table->boolean('requires_attachment')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        $now = now();
        $type = static fn (string $code, string $ar, string $en, bool $deducts, bool $requests, bool $reason, bool $attachment = false): array => [
            'code'                => $code,
            'name_ar'             => $ar,
            'name_en'             => $en,
            'deducts_balance'     => $deducts,
            'for_requests'        => $requests,
            'for_excuses'         => true,
            'requires_reason'     => $reason,
            'requires_attachment' => $attachment,
            'is_active'           => true,
            'created_at'          => $now,
            'updated_at'          => $now,
        ];

        DB::table('leave_types')->insert([
            $type('annual', 'إجازة سنوية', 'Annual leave', true, true, false),
            $type('emergency', 'إجازة طارئة', 'Emergency leave', true, true, false),
            $type('sick', 'إجازة مرضية', 'Sick leave', false, true, true, attachment: true),
            $type('unpaid', 'إجازة بدون راتب', 'Unpaid leave', false, true, true),
            $type('official_mission', 'مأمورية رسمية', 'Official mission', false, false, true),
            $type('bereavement', 'إجازة وفاة', 'Bereavement leave', false, false, true),
            $type('attendance_excused', 'حضور مبرر', 'Excused attendance', false, false, true),
            $type('external_task', 'مهمة خارجية', 'External task', false, false, true),
            $type('study_leave', 'إجازة دراسة', 'Study leave', false, false, false),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
