<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Models\Shift;
use Database\Seeders\SchedulingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Work shifts plan Task 4: /api/scheduling/shifts (spec §9.2). */
class ShiftEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SchedulingSeeder::class);
    }

    private function valid(array $overrides = []): array
    {
        return array_merge(['code' => 'NIGHT', 'name' => 'وردية ليلية', 'starts_at' => '22:00', 'duration_minutes' => 480], $overrides);
    }

    public function test_the_list_and_one_shift_are_read_with_view(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.view']);

        $this->getJson('/api/scheduling/shifts')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', 'DAY')
            ->assertJsonPath('data.0.starts_at', '09:00')
            ->assertJsonPath('data.0.ends_next_day', false);

        $guard = Shift::where('code', 'GUARD')->value('id');
        $this->getJson("/api/scheduling/shifts/{$guard}")->assertOk()
            ->assertJsonPath('data.duration_minutes', 1440);
    }

    public function test_a_shift_is_created_with_manage(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);

        $this->postJson('/api/scheduling/shifts', $this->valid())->assertCreated()
            ->assertJsonPath('data.code', 'NIGHT')
            ->assertJsonPath('data.ends_next_day', true)
            ->assertJsonPath('data.is_active', true);
    }

    public function test_writes_need_manage(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.view']);
        $day = Shift::where('code', 'DAY')->value('id');

        $this->postJson('/api/scheduling/shifts', $this->valid())->assertForbidden();
        $this->patchJson("/api/scheduling/shifts/{$day}", ['name' => 'x'])->assertForbidden();
        $this->deleteJson("/api/scheduling/shifts/{$day}")->assertForbidden();
    }

    public function test_reads_need_view(): void
    {
        $this->actingAsRolelessUser();

        $this->getJson('/api/scheduling/shifts')->assertForbidden();
    }

    public function test_the_fields_are_validated(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);

        $this->postJson('/api/scheduling/shifts', $this->valid(['starts_at' => '25:00', 'duration_minutes' => 1441, 'code' => 'DAY']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at', 'duration_minutes', 'code']);
    }

    public function test_a_code_stays_taken_by_a_deleted_shift(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);
        Shift::create($this->valid(['code' => 'OLD']))->delete();

        $this->postJson('/api/scheduling/shifts', $this->valid(['code' => 'OLD']))
            ->assertUnprocessable()->assertJsonValidationErrors(['code']);
    }

    public function test_a_used_shifts_time_cannot_change(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);
        $day = Shift::where('code', 'DAY')->value('id');

        $this->patchJson("/api/scheduling/shifts/{$day}", ['starts_at' => '08:00'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'لا يمكن تعديل وقت وردية مستخدمة في جدول؛ أنشئ وردية جديدة.');

        $this->patchJson("/api/scheduling/shifts/{$day}", ['name' => 'دوام الإدارة', 'is_active' => false])->assertOk()
            ->assertJsonPath('data.name', 'دوام الإدارة')
            ->assertJsonPath('data.is_active', false);
    }

    public function test_an_unused_shift_is_deleted_and_a_used_one_is_refused(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);
        $night = Shift::create($this->valid());

        $this->deleteJson("/api/scheduling/shifts/{$night->id}")->assertOk();
        $this->assertSoftDeleted($night);

        $this->deleteJson('/api/scheduling/shifts/'.Shift::where('code', 'DAY')->value('id'))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'لا يمكن حذف وردية مستخدمة في جدول.');
    }
}
