<?php

declare(strict_types=1);

use App\Modules\Attendance\Support\ShiftRules;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every existing shift gets one rules version from 2000-01-01 carrying the
 * company-wide values in force until now (per-shift rules spec §2.2), so
 * nothing changes until HR edits a shift. A DATA MIGRATION — every attendance
 * read needs a shift's rules. Idempotent: a shift that already has a version
 * is left alone.
 *
 * down() leaves the rows: the table's own migration drops them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $shifts = DB::table('shifts')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('shift_rules')->whereColumn('shift_rules.shift_id', 'shifts.id'))
            ->get(['id', 'duration_minutes']);

        foreach ($shifts as $shift) {
            DB::table('shift_rules')->insert(ShiftRules::defaults((int) $shift->duration_minutes)->toArray() + [
                'shift_id'       => $shift->id,
                'effective_from' => ShiftRules::FIRST_DAY,
                'created_by'     => null,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
        }
    }

    public function down(): void
    {
        //
    }
};
