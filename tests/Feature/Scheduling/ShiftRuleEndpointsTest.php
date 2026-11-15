<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Attendance\Support\ShiftRules;
use App\Modules\Scheduling\Models\Shift;
use Database\Seeders\SchedulingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Per-shift rules plan Task 3: the rules API (spec §5.1). Today is 2026-11-14 (Damascus). */
class ShiftRuleEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-14 10:00:00');
        $this->seed(SchedulingSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function day(): Shift
    {
        return Shift::where('code', 'DAY')->sole();
    }

    /** @return array<string, mixed> */
    private function version(array $overrides = []): array
    {
        return array_merge([
            'effective_from'            => '2026-11-15',
            'late_grace_minutes'        => 5,
            'early_leave_grace_minutes' => 20,
            'check_in_opens_minutes'    => 30,
            'check_out_closes_minutes'  => 120,
            'overtime_enabled'          => true,
            'overtime_min_minutes'      => 30,
            'required_minutes'          => 390,
        ], $overrides);
    }

    public function test_a_shift_is_created_with_its_first_rules(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage', 'scheduling.view']);

        $id = $this->postJson('/api/scheduling/shifts', [
            'code' => 'NIGHT', 'name' => 'ليلي', 'starts_at' => '22:00', 'duration_minutes' => 480,
            'late_grace_minutes' => 5, 'overtime_enabled' => true, 'overtime_min_minutes' => 30,
        ])->assertCreated()
            ->assertJsonPath('data.rules.late_grace_minutes', 5)
            ->assertJsonPath('data.rules.early_leave_grace_minutes', 15)
            ->assertJsonPath('data.rules.overtime_enabled', true)
            ->assertJsonPath('data.rules.required_minutes', 480)
            ->json('data.id');

        $this->assertCount(1, Shift::findOrFail($id)->rules()->get());
        $this->assertSame(ShiftRules::FIRST_DAY, Shift::findOrFail($id)->rules()->sole()->effective_from->toDateString());
    }

    public function test_required_minutes_cannot_exceed_the_length_on_create(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);

        $this->postJson('/api/scheduling/shifts', [
            'code' => 'NIGHT', 'name' => 'ليلي', 'starts_at' => '22:00', 'duration_minutes' => 480, 'required_minutes' => 481,
        ])->assertUnprocessable()->assertJsonPath('message', 'الساعات المطلوبة لا تتجاوز طول الوردية.');
        $this->assertNull(Shift::where('code', 'NIGHT')->first());
    }

    public function test_rules_cannot_be_patched_onto_a_shift(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);

        $this->patchJson("/api/scheduling/shifts/{$this->day()->id}", ['late_grace_minutes' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors(['late_grace_minutes']);
    }

    public function test_the_list_and_one_shift_show_the_rules_in_force_and_one_shows_its_versions(): void
    {
        $this->day()->rules()->create(['effective_from' => '2026-11-15', 'late_grace_minutes' => 5] + ShiftRules::defaults(420)->toArray());
        $this->actingAsUserWithPermissions(['scheduling.view']);

        $this->getJson('/api/scheduling/shifts')->assertOk()
            ->assertJsonPath('data.0.code', 'DAY')
            ->assertJsonPath('data.0.rules.late_grace_minutes', 15)
            ->assertJsonMissingPath('data.0.rule_versions');

        $this->getJson("/api/scheduling/shifts/{$this->day()->id}")->assertOk()
            ->assertJsonPath('data.rules.late_grace_minutes', 15)
            ->assertJsonCount(2, 'data.rule_versions')
            ->assertJsonPath('data.rule_versions.0.effective_from', '2026-11-15')
            ->assertJsonPath('data.rule_versions.0.late_grace_minutes', 5)
            ->assertJsonPath('data.rule_versions.1.effective_from', '2000-01-01');
    }

    public function test_a_version_is_added_from_today_or_later(): void
    {
        $user = $this->actingAsUserWithPermissions(['scheduling.manage', 'scheduling.view']);

        $this->postJson("/api/scheduling/shifts/{$this->day()->id}/rules", $this->version())->assertCreated()
            ->assertJsonPath('message', 'تم حفظ قواعد الوردية.')
            ->assertJsonPath('data.effective_from', '2026-11-15')
            ->assertJsonPath('data.early_leave_grace_minutes', 20)
            ->assertJsonPath('data.created_by.id', $user->id);

        $this->postJson("/api/scheduling/shifts/{$this->day()->id}/rules", $this->version(['effective_from' => '2026-11-14']))->assertCreated();
        $this->assertSame(5, $this->day()->fresh()->rulesOn('2026-11-15')->lateGraceMinutes);
    }

    public function test_a_version_cannot_start_before_today(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);

        $this->postJson("/api/scheduling/shifts/{$this->day()->id}/rules", $this->version(['effective_from' => '2026-11-13']))
            ->assertUnprocessable()->assertJsonPath('message', 'لا يمكن أن تبدأ القواعد قبل اليوم.');
    }

    public function test_one_version_per_date(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);
        $this->postJson("/api/scheduling/shifts/{$this->day()->id}/rules", $this->version())->assertCreated();

        $this->postJson("/api/scheduling/shifts/{$this->day()->id}/rules", $this->version())
            ->assertUnprocessable()->assertJsonPath('message', 'توجد قواعد لهذه الوردية من التاريخ نفسه.');
    }

    public function test_a_versions_required_minutes_cannot_exceed_the_length(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);

        $this->postJson("/api/scheduling/shifts/{$this->day()->id}/rules", $this->version(['required_minutes' => 421]))
            ->assertUnprocessable()->assertJsonPath('message', 'الساعات المطلوبة لا تتجاوز طول الوردية.');
    }

    public function test_each_field_has_its_range(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);
        $url = "/api/scheduling/shifts/{$this->day()->id}/rules";

        foreach ([
            'late_grace_minutes'        => [-1, 121],
            'early_leave_grace_minutes' => [-1, 121],
            'check_in_opens_minutes'    => [-1, 241],
            'check_out_closes_minutes'  => [-1, 481],
            'overtime_min_minutes'      => [-1, 241],
            'required_minutes'          => [0, null],
        ] as $field => $bad) {
            foreach (array_filter($bad, fn ($v) => $v !== null) as $value) {
                $this->postJson($url, $this->version([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors([$field]);
            }
        }
        $this->postJson($url, $this->version(['late_grace_minutes' => 120, 'check_in_opens_minutes' => 240, 'check_out_closes_minutes' => 480, 'overtime_min_minutes' => 240]))
            ->assertCreated();
    }

    public function test_a_version_not_yet_started_is_deleted(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);
        $rule = $this->day()->rules()->create(['effective_from' => '2026-11-15'] + ShiftRules::defaults(420)->toArray());

        $this->deleteJson("/api/scheduling/shifts/{$this->day()->id}/rules/{$rule->id}")->assertOk()
            ->assertJsonPath('message', 'تم حذف قواعد الوردية.');
        $this->assertCount(1, $this->day()->rules()->get());
    }

    public function test_a_started_or_first_version_is_not_deleted(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);
        $today = $this->day()->rules()->create(['effective_from' => '2026-11-14'] + ShiftRules::defaults(420)->toArray());
        $first = $this->day()->rules()->where('effective_from', ShiftRules::FIRST_DAY)->sole();

        foreach ([$today, $first] as $rule) {
            $this->deleteJson("/api/scheduling/shifts/{$this->day()->id}/rules/{$rule->id}")
                ->assertUnprocessable()->assertJsonPath('message', 'لا يمكن حذف قواعد بدأ العمل بها.');
        }
        $this->assertCount(2, $this->day()->rules()->get());
    }

    public function test_another_shifts_rule_is_not_found(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);
        $guard = Shift::where('code', 'GUARD')->sole();
        $rule = $guard->rules()->create(['effective_from' => '2026-11-15'] + ShiftRules::defaults(1440)->toArray());

        $this->deleteJson("/api/scheduling/shifts/{$this->day()->id}/rules/{$rule->id}")->assertNotFound();
    }

    public function test_changing_an_unused_shifts_length_moves_its_required_minutes(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage', 'scheduling.view']);
        $id = $this->postJson('/api/scheduling/shifts', ['code' => 'NIGHT', 'name' => 'ليلي', 'starts_at' => '22:00', 'duration_minutes' => 480])
            ->assertCreated()->json('data.id');
        $shift = Shift::findOrFail($id);
        $shift->rules()->create(['effective_from' => '2026-11-20', 'required_minutes' => 300] + ShiftRules::defaults(480)->toArray());
        $shift->rules()->create(['effective_from' => '2026-11-25', 'required_minutes' => 450] + ShiftRules::defaults(480)->toArray());

        $this->patchJson("/api/scheduling/shifts/{$id}", ['duration_minutes' => 420])->assertOk()
            ->assertJsonPath('data.rules.required_minutes', 420);

        $this->assertSame(
            ['2000-01-01' => 420, '2026-11-20' => 300, '2026-11-25' => 420],
            $shift->rules()->get()->mapWithKeys(fn ($r) => [$r->effective_from->toDateString() => $r->required_minutes])->all(),
            'the whole shift follows the length; a lower requirement stays; one above the new length is capped',
        );

        $this->patchJson("/api/scheduling/shifts/{$id}", ['duration_minutes' => 540])->assertOk();
        $this->assertSame(540, $shift->fresh()->rulesOn('2026-11-14')->requiredMinutes, 'the whole shift grows with it');
    }

    public function test_the_schedules_list_reads_each_shifts_rules_once(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.view']);

        DB::enableQueryLog();
        $this->getJson('/api/scheduling/schedules')->assertOk();
        $rulesQueries = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'shift_rules'))->count();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(2, $rulesQueries, 'DAY and GUARD, not one per schedule day');
    }

    public function test_writes_need_manage(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.view']);
        $rule = $this->day()->rules()->create(['effective_from' => '2026-11-15'] + ShiftRules::defaults(420)->toArray());

        $this->postJson("/api/scheduling/shifts/{$this->day()->id}/rules", $this->version())->assertForbidden();
        $this->deleteJson("/api/scheduling/shifts/{$this->day()->id}/rules/{$rule->id}")->assertForbidden();
    }
}
