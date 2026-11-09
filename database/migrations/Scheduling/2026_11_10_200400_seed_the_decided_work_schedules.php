<?php

declare(strict_types=1);

use Database\Seeders\SchedulingSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * The decided schedules as data (work shifts spec §3.4; final review I6):
 * DAY/OFFICE — the default every unassigned person is on — and GUARD.
 *
 * A DATA MIGRATION, not only a seeder, because attendance cannot run without
 * the default: the resolver refuses to guess one, so a deploy that ran
 * `migrate` and forgot `db:seed` would fail every attendance and leave call.
 * SchedulingSeeder is idempotent (by code), so running it here and again
 * from the seeders changes nothing.
 *
 * down() leaves the rows: they are people's schedules, and the tables' own
 * migrations drop them when rolled back.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new SchedulingSeeder)->run();
    }

    public function down(): void
    {
        //
    }
};
