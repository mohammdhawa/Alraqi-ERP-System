<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Modules\Auth\Models\User;
use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use App\Modules\Scheduling\Models\WorkSchedule;
use App\Modules\Scheduling\Services\ScheduleAssigner;
use Database\Seeders\SchedulingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Work shifts plan Task 7: GET /api/scheduling/calendar (spec §9.2). */
class ScheduleCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-14 06:00:00');
        $this->seed(SchedulingSeeder::class);
        config(['attendance.default_schedule' => 'OFFICE']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_everyone_s_expected_shift_per_date_and_its_source(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.view']);
        $guard = Employee::factory()->create();
        app(ScheduleAssigner::class)->assign($guard, WorkSchedule::where('code', 'GUARD')->sole(), '2026-11-14', '2026-11-14', User::factory()->create());
        $office = Employee::factory()->create();

        $response = $this->getJson("/api/scheduling/calendar?from=2026-11-14&to=2026-11-20&employee_id={$guard->id}")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee.id', $guard->id)
            ->assertJsonCount(7, 'data.0.days')
            ->assertJsonPath('data.0.days.0', ['date' => '2026-11-14', 'shift' => [
                'code' => 'GUARD', 'name' => 'مناوبة حراسة', 'starts_at' => '2026-11-14T08:00:00+03:00', 'ends_at' => '2026-11-15T08:00:00+03:00',
            ], 'source' => 'assignment'])
            ->assertJsonPath('data.0.days.3', ['date' => '2026-11-17', 'shift' => null, 'source' => 'assignment']);

        $this->getJson("/api/scheduling/calendar?from=2026-11-20&to=2026-11-21&employee_id={$office->id}")->assertOk()
            ->assertJsonPath('data.0.days.0', ['date' => '2026-11-20', 'shift' => null, 'source' => 'default'])
            ->assertJsonPath('data.0.days.1.shift.code', 'DAY');

        $this->assertSame(1, $response->json('meta.total'));
    }

    /** @return array{0: array<string, Department>, 1: array<string, Employee>} */
    private function unitsAndPeople(): array
    {
        $level = static fn (int $index): int => DepartmentLevel::cases()[$index]->value;
        $gm = Department::create(['name' => 'الإدارة العامة', 'code' => 'GM', 'level' => $level(0), 'parent_id' => null]);
        $a = Department::create(['name' => 'إدارة أ', 'code' => 'A', 'level' => $level(1), 'parent_id' => $gm->id]);
        $a1 = Department::create(['name' => 'قسم أ-1', 'code' => 'A1', 'level' => $level(2), 'parent_id' => $a->id]);
        $b = Department::create(['name' => 'إدارة ب', 'code' => 'B', 'level' => $level(1), 'parent_id' => $gm->id]);
        $units = compact('a', 'a1', 'b');
        $people = [];
        foreach ($units as $key => $unit) {
            $people[$key] = Employee::factory()->create(['department_id' => $unit->id]);
        }

        return [$units, $people];
    }

    /** @return array<int, int> */
    private function listedIds(string $query): array
    {
        $ids = array_map(fn (array $row): int => $row['employee']['id'], $this->getJson("/api/scheduling/calendar?from=2026-11-14&to=2026-11-15&{$query}")->assertOk()->json('data'));
        sort($ids);

        return $ids;
    }

    public function test_a_person_who_left_is_not_listed(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.view']);
        Employee::factory()->create(['status' => EmployeeStatus::Terminated]);
        $active = Employee::factory()->create();

        $this->getJson('/api/scheduling/calendar?from=2026-11-14&to=2026-11-15')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.employee.id', $active->id);
    }

    public function test_a_suspended_person_is_still_listed(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.view']);
        $suspended = Employee::factory()->create(['status' => EmployeeStatus::Suspended]);
        Employee::factory()->create(['status' => EmployeeStatus::Terminated]);

        $this->getJson('/api/scheduling/calendar?from=2026-11-14&to=2026-11-15')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.employee.id', $suspended->id);
    }

    public function test_a_unit_filter_includes_its_sub_units(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.view']);
        [$units, $people] = $this->unitsAndPeople();

        $this->getJson("/api/scheduling/calendar?from=2026-11-14&to=2026-11-15&department_id={$units['a']->id}")->assertOk()
            ->assertJsonPath('meta.total', 2);
        $this->assertSame([$people['a']->id, $people['a1']->id], $this->listedIds("department_id={$units['a']->id}"));
    }

    public function test_unit_and_person_lists_take_csv_or_an_array(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.view']);
        [$units, $people] = $this->unitsAndPeople();
        $expected = [$people['a1']->id, $people['b']->id];
        sort($expected);

        $this->assertSame($expected, $this->listedIds("department_ids={$units['a1']->id},{$units['b']->id}"));
        $this->assertSame($expected, $this->listedIds("department_ids[]={$units['a1']->id}&department_ids[]={$units['b']->id}"));
        $this->assertSame($expected, $this->listedIds("employee_ids={$people['a1']->id},{$people['b']->id}"));
        $this->assertSame($expected, $this->listedIds("employee_ids[]={$people['a1']->id}&employee_ids[]={$people['b']->id}"));
    }

    public function test_search_by_name_part_or_staff_number(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.view']);
        $target = Employee::factory()->named('سامر خالد الحلبي')->create();
        Employee::factory()->named('ليلى يوسف')->create();

        $this->assertSame([$target->id], $this->listedIds('search='.urlencode('خالد')));
        $this->assertSame([$target->id], $this->listedIds('search='.urlencode((string) $target->employee_number)));
    }

    public function test_an_unknown_unit_narrows_to_nobody(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.view']);
        Employee::factory()->create();

        $this->getJson('/api/scheduling/calendar?from=2026-11-14&to=2026-11-15&department_id=999999')->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_at_most_92_days(): void
    {
        $this->actingAsUserWithPermissions(['scheduling.view']);

        $this->getJson('/api/scheduling/calendar?from=2026-11-01&to=2027-02-01')
            ->assertUnprocessable()->assertJsonValidationErrors(['to']);
    }

    public function test_it_needs_view(): void
    {
        $this->actingAsRolelessUser();

        $this->getJson('/api/scheduling/calendar?from=2026-11-14&to=2026-11-20')->assertForbidden();
    }
}
