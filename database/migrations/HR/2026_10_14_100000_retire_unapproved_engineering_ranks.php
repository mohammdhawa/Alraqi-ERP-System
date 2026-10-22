<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Move any row off ركن (9), which is not an engineering rank (work order §8,
 * 2026-10-14). EngineeringRank no longer has case 9, and the model's enum cast
 * throws a ValueError on a value it does not know, so a row still holding it
 * could not even be read.
 *
 * ركن goes to استشاري (8), the highest rank that remains. No row is known to
 * hold it — no employee has been imported, ركن was never priced, and no seeder
 * used it — so this is a guard for any database that does.
 *
 * ⚙️ An earlier draft of this migration (never committed) also moved مشارك (5)
 * to مشارك1 (10) and استشاري (8) to رأي (7), on a misreading of HR's answers;
 * both ARE ranks. A development database that ran that draft has its two
 * seeded engineers to put back by hand — see the work order §8 correction.
 *
 * Raw queries, not the model: the model cannot read the rows being fixed. No
 * down(): 9 is not a rank any more.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('employees')->where('engineering_rank', 9)->update(['engineering_rank' => 8]);
    }
};
