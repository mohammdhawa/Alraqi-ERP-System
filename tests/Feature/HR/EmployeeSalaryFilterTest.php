<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Support\PermissionCache;
use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Enums\ActivityType;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Policies\EmployeePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A filter is a read: `?activity_type=` on the employee list.
 *
 * `activity_type` is one of the classification axes `hr.employees.viewSalary`
 * gates on the Resource and on write, because pay is computed from them. The
 * list accepted it as a filter from anyone holding `hr.employees.view`, so a
 * caller refused the field on every employee could still ask "which of you are
 * engineers?" one value at a time and read the answer off the rows returned.
 *
 * Two layers, both asserted: no pay grant at all → 422 naming the field; a pay
 * grant over some units → the filtered rows are only those it reaches.
 *
 * No test acts as a super admin except the one asserting the bypass survives.
 *
 *   GM ── A ── A1      A1: an engineer and an administrator (pay grant here)
 *    └─── B            B:  an engineer (outside the pay grant)
 */
class EmployeeSalaryFilterTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Department> */
    private array $units = [];

    /** @var array<string, Employee> */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();

        $level = static fn (int $index): int => DepartmentLevel::cases()[$index]->value;

        $gm = Department::create(['name' => 'الإدارة العامة', 'code' => 'GM', 'level' => $level(0), 'parent_id' => null]);
        $a  = Department::create(['name' => 'إدارة أ', 'code' => 'A', 'level' => $level(1), 'parent_id' => $gm->id]);
        $a1 = Department::create(['name' => 'قسم أ-1', 'code' => 'A1', 'level' => $level(2), 'parent_id' => $a->id]);
        $b  = Department::create(['name' => 'إدارة ب', 'code' => 'B', 'level' => $level(1), 'parent_id' => $gm->id]);

        $this->units = compact('gm', 'a', 'a1', 'b');

        $this->people = [
            'a1Engineer' => Employee::factory()->create(['department_id' => $a1->id, 'activity_type' => ActivityType::Engineering->value]),
            'a1Admin'    => Employee::factory()->create(['department_id' => $a1->id, 'activity_type' => ActivityType::Administrative->value]),
            'bEngineer'  => Employee::factory()->create(['department_id' => $b->id, 'activity_type' => ActivityType::Engineering->value]),
        ];
    }

    /**
     * A company-wide viewer, plus — when `$payScope` is given — a viewSalary
     * grant over that scope (section A1 for a unit scope).
     */
    private function actingAsViewer(?ScopeType $payScope = null): User
    {
        $user = $this->actingAsScopedUser(['hr.employees.view'], ScopeType::Company);

        if ($payScope !== null) {
            $pay = Role::create(['name' => 'pay-'.uniqid()]);
            $pay->permissions()->sync(Permission::where('name', 'hr.employees.viewSalary')->pluck('id'));

            $user->roles()->attach($pay->id, [
                'scope_type'          => $payScope->value,
                'scope_department_id' => $payScope === ScopeType::Unit ? $this->units['a1']->id : null,
            ]);
            PermissionCache::flush();
        }

        return $user;
    }

    /**
     * @return array<int, int> sorted ids of the rows returned
     */
    private function engineersListed(): array
    {
        $ids = array_column(
            $this->getJson('/api/hr/employees?per_page=100&activity_type='.ActivityType::Engineering->value)
                ->assertOk()
                ->json('data'),
            'id',
        );
        sort($ids);

        return $ids;
    }

    /**
     * @return array<int, int>
     */
    private function idsOf(string ...$keys): array
    {
        $ids = array_map(fn (string $key): int => $this->people[$key]->id, $keys);
        sort($ids);

        return $ids;
    }

    // ── No pay grant at all: refused, by name ────────────────────────────

    public function test_a_viewer_without_view_salary_cannot_filter_by_a_pay_field(): void
    {
        $this->actingAsViewer();

        $this->getJson('/api/hr/employees?activity_type='.ActivityType::Engineering->value)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['activity_type'])
            ->assertJsonPath('errors.activity_type.0', 'لا تملك صلاحية التصفية حسب حقول الرواتب ومعاييرها.');
    }

    public function test_the_same_viewer_still_lists_without_that_filter(): void
    {
        $this->actingAsViewer();

        $this->getJson('/api/hr/employees?per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.total', count($this->people));
    }

    // ── A pay grant over SOME units: filtered within it ──────────────────

    public function test_a_section_scoped_pay_grant_filters_only_within_its_section(): void
    {
        $this->actingAsViewer(ScopeType::Unit);

        // B's engineer matches the filter and is readable as a ROW — the view
        // grant is company-wide — but their activity type is not the caller's
        // to know, so the filter must not return them.
        $this->assertSame($this->idsOf('a1Engineer'), $this->engineersListed());

        $this->getJson('/api/hr/employees?per_page=100&activity_type='.ActivityType::Engineering->value)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_the_narrowing_applies_only_while_a_pay_field_is_used(): void
    {
        $this->actingAsViewer(ScopeType::Unit);

        $this->getJson('/api/hr/employees?per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.total', count($this->people));
    }

    // ── A company-wide pay grant, and the bypass ─────────────────────────

    public function test_a_company_wide_pay_grant_filters_the_whole_company(): void
    {
        $this->actingAsViewer(ScopeType::Company);

        $this->assertSame($this->idsOf('a1Engineer', 'bEngineer'), $this->engineersListed());
    }

    public function test_a_super_admin_is_unaffected(): void
    {
        $this->actingAsAdmin();

        $this->assertSame($this->idsOf('a1Engineer', 'bEngineer'), $this->engineersListed());
    }

    // ── The gated keys are derived, not listed ───────────────────────────

    public function test_every_filterable_pay_field_is_gated_and_activity_type_is_one(): void
    {
        // The gate reads the intersection of what the list can filter by and
        // what the salary gate covers. If that intersection ever came back
        // empty, the gate would silently cover nothing — this pins that it
        // holds the field this test file exists for.
        $this->assertContains('activity_type', EmployeePolicy::salaryGatedFilters());
    }
}
