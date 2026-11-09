<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Scheduling\Models\EmployeeWorkSchedule;
use App\Modules\Scheduling\Models\Shift;
use App\Modules\Scheduling\Models\WorkSchedule;
use Database\Seeders\SchedulingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Work shifts plan Task 4: /api/scheduling/schedules (spec §9.2). */
class WorkScheduleEndpointsTest extends TestCase
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

    private function guardId(): int
    {
        return (int) Shift::where('code', 'GUARD')->value('id');
    }

    /** @param  array<int, ?int>  $shifts */
    private function body(array $shifts, array $overrides = []): array
    {
        return array_merge([
            'code' => 'G2', 'name' => 'حراسة ثانية', 'kind' => 2, 'cycle_days' => count($shifts),
            'days' => array_map(fn (int $p, ?int $s): array => ['position' => $p, 'shift_id' => $s], array_keys($shifts), $shifts),
        ], $overrides);
    }

    public function test_the_list_and_one_schedule_are_read_with_view(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.view']);

        $this->getJson('/api/scheduling/schedules')->assertOk()->assertJsonCount(2, 'data');

        $office = WorkSchedule::where('code', 'OFFICE')->value('id');
        $this->getJson("/api/scheduling/schedules/{$office}")->assertOk()
            ->assertJsonPath('data.kind', 1)
            ->assertJsonPath('data.kind_label', 'أسبوعي')
            ->assertJsonPath('data.days.5.position', 5)
            ->assertJsonPath('data.days.5.shift', null)
            ->assertJsonPath('data.days.6.shift.code', 'DAY');
    }

    public function test_a_schedule_is_created_with_its_days(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);
        $g = $this->guardId();

        $this->postJson('/api/scheduling/schedules', $this->body([$g, $g, null, null]))->assertCreated()
            ->assertJsonPath('data.code', 'G2')
            ->assertJsonPath('data.cycle_days', 4)
            ->assertJsonCount(4, 'data.days');
    }

    public function test_a_fourth_back_to_back_shift_is_a_field_error(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);
        $g = $this->guardId();

        $this->postJson('/api/scheduling/schedules', $this->body([$g, $g, $g, $g, null, null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['days' => 'لا يمكن أن تتصل أكثر من ثلاث ورديات متتالية بلا فاصل.']);
    }

    public function test_writes_need_manage(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.view']);

        $this->postJson('/api/scheduling/schedules', $this->body([$this->guardId()]))->assertForbidden();
    }

    public function test_an_unassigned_schedules_days_can_be_replaced(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);
        $g = $this->guardId();
        $id = $this->postJson('/api/scheduling/schedules', $this->body([$g, null]))->json('data.id');

        $this->patchJson("/api/scheduling/schedules/{$id}", ['name' => 'معدّل', 'days' => [
            ['position' => 0, 'shift_id' => null], ['position' => 1, 'shift_id' => $g],
        ]])->assertOk()
            ->assertJsonPath('data.name', 'معدّل')
            ->assertJsonPath('data.days.0.shift', null)
            ->assertJsonPath('data.days.1.shift.code', 'GUARD');
    }

    public function test_an_assigned_schedules_days_are_refused_and_it_cannot_be_deleted(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);
        $office = WorkSchedule::where('code', 'OFFICE')->sole();
        EmployeeWorkSchedule::create([
            'employee_id' => Employee::factory()->create()->id, 'work_schedule_id' => $office->id,
            'from_date' => '2026-11-14', 'created_by' => User::factory()->create()->id,
        ]);

        $this->patchJson("/api/scheduling/schedules/{$office->id}", ['days' => array_map(
            fn (int $p): array => ['position' => $p, 'shift_id' => null], range(0, 6),
        )])->assertUnprocessable()
            ->assertJsonPath('message', 'لا يمكن تعديل أيام جدول مُسند لموظفين؛ أنشئ جدولاً جديداً.');

        $this->patchJson("/api/scheduling/schedules/{$office->id}", ['name' => 'الإدارة'])->assertOk();

        $this->deleteJson("/api/scheduling/schedules/{$office->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'لا يمكن حذف جدول مُسند لموظفين.');
    }

    public function test_an_unassigned_schedule_is_deleted(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);
        $guard = WorkSchedule::where('code', 'GUARD')->sole();

        $this->deleteJson("/api/scheduling/schedules/{$guard->id}")->assertOk();
        $this->assertSoftDeleted($guard);
    }
}
