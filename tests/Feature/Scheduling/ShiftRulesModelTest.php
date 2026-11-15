<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Models\Shift;
use App\Modules\Scheduling\Models\ShiftRule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Per-shift rules plan Task 1: dated rule versions (spec §2). */
class ShiftRulesModelTest extends TestCase
{
    use RefreshDatabase;

    private const SEED_MIGRATION = 'migrations/Scheduling/2026_11_12_100100_seed_rules_for_existing_shifts.php';

    public function test_a_new_shift_gets_a_default_first_version(): void
    {
        $shift = Shift::create(['code' => 'NIGHT', 'name' => 'ليلي', 'starts_at' => '22:00', 'duration_minutes' => 480]);

        $this->assertCount(1, $shift->rules()->get());
        $rule = $shift->rules()->sole();
        $this->assertSame('2000-01-01', $rule->effective_from->toDateString());
        $this->assertSame(
            [15, 15, 60, 240, false, 0, 480],
            [$rule->late_grace_minutes, $rule->early_leave_grace_minutes, $rule->check_in_opens_minutes,
                $rule->check_out_closes_minutes, $rule->overtime_enabled, $rule->overtime_min_minutes, $rule->required_minutes],
        );
        $this->assertNull($rule->created_by);
    }

    public function test_rules_on_picks_the_latest_version_on_or_before_the_date(): void
    {
        $shift = Shift::create(['code' => 'NIGHT', 'name' => 'ليلي', 'starts_at' => '22:00', 'duration_minutes' => 480]);
        $shift->rules()->create($this->values(['effective_from' => '2026-12-01', 'late_grace_minutes' => 5]));

        $this->assertSame(15, $shift->fresh()->rulesOn('2026-11-30')->lateGraceMinutes);
        $this->assertSame(5, $shift->fresh()->rulesOn('2026-12-01')->lateGraceMinutes);
        $this->assertSame(5, $shift->fresh()->rulesOn('2027-01-01')->lateGraceMinutes);
    }

    public function test_rules_on_uses_the_loaded_versions(): void
    {
        $shift = Shift::with('rules')->where('code', 'DAY')->sole();

        \DB::enableQueryLog();
        $shift->rulesOn('2026-11-14');
        $shift->rulesOn('2026-11-15');

        $this->assertSame([], \DB::getQueryLog());
    }

    public function test_the_migration_seeds_every_existing_shift(): void
    {
        ShiftRule::query()->delete();

        $migration = require database_path(self::SEED_MIGRATION);
        $migration->up();
        $migration->up();

        foreach (Shift::with('rules')->get() as $shift) {
            $this->assertCount(1, $shift->rules, $shift->code);
            $rule = $shift->rules->sole();
            $this->assertSame('2000-01-01', $rule->effective_from->toDateString());
            $this->assertSame([15, 15, 60, 240, false, 0, $shift->duration_minutes], [
                $rule->late_grace_minutes, $rule->early_leave_grace_minutes, $rule->check_in_opens_minutes,
                $rule->check_out_closes_minutes, $rule->overtime_enabled, $rule->overtime_min_minutes, $rule->required_minutes,
            ]);
        }
        $this->assertSame(['DAY', 'GUARD'], Shift::orderBy('code')->pluck('code')->all());
    }

    public function test_effective_from_is_unique_per_shift(): void
    {
        $shift = Shift::where('code', 'DAY')->sole();

        $this->expectException(QueryException::class);
        $shift->rules()->create($this->values(['effective_from' => '2000-01-01']));
    }

    /** @return array<string, mixed> */
    private function values(array $overrides): array
    {
        return array_merge([
            'late_grace_minutes'        => 15,
            'early_leave_grace_minutes' => 15,
            'check_in_opens_minutes'    => 60,
            'check_out_closes_minutes'  => 240,
            'overtime_enabled'          => false,
            'overtime_min_minutes'      => 0,
            'required_minutes'          => 420,
        ], $overrides);
    }
}
