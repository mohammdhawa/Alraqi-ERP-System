<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Auth\Models\User;
use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Scheduling\Models\EmployeeWorkSchedule;
use App\Modules\Scheduling\Models\WorkSchedule;
use App\Modules\Scheduling\Services\ScheduleAssigner;
use Database\Seeders\SchedulingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Work shifts plan Task 5: POST /api/scheduling/assignments/bulk (spec §9.2). */
class BulkAssignmentTest extends TestCase
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

    private function guard(): WorkSchedule
    {
        return WorkSchedule::where('code', 'GUARD')->sole();
    }

    public function test_each_person_is_assigned_or_refused_on_their_own(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);
        [$a, $b, $c] = Employee::factory()->count(3)->create()->all();
        app(ScheduleAssigner::class)->assign($c, $this->guard(), '2026-11-20', '2026-11-20', User::factory()->create());

        $this->postJson('/api/scheduling/assignments/bulk', [
            'work_schedule_id' => $this->guard()->id, 'from_date' => '2026-11-20', 'cycle_start_date' => '2026-11-20',
            'employee_ids'     => [$a->id, $b->id, $c->id],
        ])->assertOk()
            ->assertJsonPath('data.0', ['employee_id' => $a->id, 'assigned' => true, 'reason' => null])
            ->assertJsonPath('data.1.assigned', true)
            ->assertJsonPath('data.2', ['employee_id' => $c->id, 'assigned' => false, 'reason' => 'تتداخل هذه الفترة مع جدول آخر مُسند للموظف.']);

        $this->assertSame(3, EmployeeWorkSchedule::count());
    }

    public function test_a_unit_and_its_subtree_are_reached(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);
        $levels = DepartmentLevel::cases();
        $gm = Department::create(['name' => 'الإدارة العامة', 'code' => 'GM', 'level' => $levels[0]->value, 'parent_id' => null]);
        $root = Department::create(['name' => 'إدارة الأمن والحماية', 'code' => 'SEC', 'level' => $levels[1]->value, 'parent_id' => $gm->id]);
        $child = Department::create(['name' => 'قسم الحماية', 'code' => 'SEC-PROT', 'level' => $levels[2]->value, 'parent_id' => $root->id]);
        $inRoot = Employee::factory()->create(['department_id' => $root->id]);
        $inChild = Employee::factory()->create(['department_id' => $child->id]);
        Employee::factory()->create();

        $body = ['work_schedule_id' => $this->guard()->id, 'from_date' => '2026-11-14', 'cycle_start_date' => '2026-11-14', 'department_id' => $root->id];

        $this->postJson('/api/scheduling/assignments/bulk', $body + ['include_subtree' => true])->assertOk()->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing([$inRoot->id, $inChild->id], EmployeeWorkSchedule::pluck('employee_id')->all());
    }

    public function test_at_most_500_people(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.manage']);

        $this->postJson('/api/scheduling/assignments/bulk', [
            'work_schedule_id' => $this->guard()->id, 'from_date' => '2026-11-14', 'cycle_start_date' => '2026-11-14',
            'employee_ids'     => range(1, 501),
        ])->assertUnprocessable()->assertJsonValidationErrors(['employee_ids']);
    }

    public function test_bulk_needs_manage(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.view']);

        $this->postJson('/api/scheduling/assignments/bulk', [])->assertForbidden();
    }
}
