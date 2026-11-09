<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Models\Shift;
use App\Modules\Scheduling\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Final review I6: the default schedule must exist on a deploy that runs only
 * `migrate` — every attendance and leave read needs it — so a data migration
 * seeds the decided schedules, idempotently.
 */
class DefaultScheduleMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/Scheduling/2026_11_10_200400_seed_the_decided_work_schedules.php';

    public function test_the_migration_seeds_office_and_guard_and_is_idempotent(): void
    {
        DB::table('work_schedule_days')->delete();
        WorkSchedule::withTrashed()->forceDelete();
        Shift::withTrashed()->forceDelete();

        $migration = require database_path(self::MIGRATION);
        $migration->up();
        $migration->up();

        $this->assertSame(['GUARD', 'OFFICE'], WorkSchedule::orderBy('code')->pluck('code')->all());
        $this->assertSame(['DAY', 'GUARD'], Shift::orderBy('code')->pluck('code')->all());
    }
}
