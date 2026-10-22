<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Shared\Services\ManagementChain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The management chain — decision 15, ratified 2026-09-23.
 *
 * One test per rule, because the rules were answered one at a time and each one
 * is a decision somebody can later ask about:
 *
 *   1. The manager of the employee's own unit.
 *   2. A vacant post, or a manager who IS the person asking, means climb.
 *   3. The walk ends at المدير العام.
 *
 * Plus the case rule 2 covers without saying so — a `manager_id` pointing at
 * someone who has left is a vacant post.
 *
 * ⚙️ **AMENDED 2026-09-24.** There was a rule 1 above it: `direct_manager_id`
 * won when set, recording an employee who reported outside their unit head. The
 * project owner ratified that the company has no such exception, so the column
 * was dropped and the two tests covering it were deleted with it. The org tree
 * is now the only source of a manager.
 */
class EmployeeManagerTest extends TestCase
{
    use RefreshDatabase;

    private ManagementChain $chain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chain = app(ManagementChain::class);
    }

    /**
     * A minimal tree: المدير العام at the root, one division under it, one
     * section under that. Returned top-down.
     *
     * @return array{0: Department, 1: Department, 2: Department}
     */
    private function tree(): array
    {
        $root = Department::create([
            'name'      => 'الإدارة العامة',
            'code'      => 'GM',
            'level'     => DepartmentLevel::cases()[0]->value,
            'parent_id' => null,
        ]);

        $division = Department::create([
            'name'      => 'إدارة الموارد البشرية',
            'code'      => 'HR',
            'level'     => DepartmentLevel::cases()[1]->value,
            'parent_id' => $root->id,
        ]);

        $section = Department::create([
            'name'      => 'قسم شؤون الموظفين',
            'code'      => 'HR-STAFF',
            'level'     => DepartmentLevel::cases()[2]->value,
            'parent_id' => $division->id,
        ]);

        return [$root, $division, $section];
    }

    // ── Rule 1 ───────────────────────────────────────────────────────────

    public function test_the_unit_manager_answers(): void
    {
        [$root, $division, $section] = $this->tree();

        $head = Employee::factory()->named('رئيس القسم')->create(['department_id' => $section->id]);
        $section->update(['manager_id' => $head->id]);

        $employee = Employee::factory()->create(['department_id' => $section->id]);

        $this->assertSame($head->id, $this->chain->resolve($employee)?->id);
    }

    /**
     * The resolved manager used to be stored as a relation with no method
     * behind it, so `refresh()` — which reloads every loaded relation by name
     * — threw RelationNotFoundException on any employee that had been walked.
     */
    public function test_a_resolved_employee_can_still_be_refreshed(): void
    {
        [$root, $division, $section] = $this->tree();

        $head = Employee::factory()->create(['department_id' => $section->id]);
        $section->update(['manager_id' => $head->id]);
        $employee = Employee::factory()->create(['department_id' => $section->id]);

        $this->chain->resolve($employee);
        $employee->refresh();

        $this->assertSame($head->id, $employee->resolvedManager()?->id);
        $this->assertArrayNotHasKey('resolvedManager', $employee->getRelations());
    }

    // ── Rule 2 ───────────────────────────────────────────────────────────

    public function test_a_vacant_post_climbs_to_the_nearest_ancestor_with_a_manager(): void
    {
        [$root, $division, $section] = $this->tree();

        $divisionHead = Employee::factory()->named('مدير الإدارة')->create(['department_id' => $division->id]);
        $division->update(['manager_id' => $divisionHead->id]);
        // The section has no manager at all — the post is vacant.

        $employee = Employee::factory()->create(['department_id' => $section->id]);

        $this->assertSame($divisionHead->id, $this->chain->resolve($employee)?->id);
    }

    /**
     * A unit head asking who their manager is wants their own superior, not
     * themselves — which is the same skip an approval needs, and the reason the
     * resolver has one entry point rather than two.
     */
    public function test_a_unit_head_resolves_to_their_own_superior_not_to_themselves(): void
    {
        [$root, $division, $section] = $this->tree();

        $divisionHead = Employee::factory()->named('مدير الإدارة')->create(['department_id' => $division->id]);
        $division->update(['manager_id' => $divisionHead->id]);

        $sectionHead = Employee::factory()->named('رئيس القسم')->create(['department_id' => $section->id]);
        $section->update(['manager_id' => $sectionHead->id]);

        $this->assertSame($divisionHead->id, $this->chain->resolve($sectionHead)?->id);
    }

    /**
     * The pointer survives the person; the post does not.
     */
    public function test_a_manager_who_has_left_reads_as_a_vacant_post(): void
    {
        [$root, $division, $section] = $this->tree();

        $rootHead = Employee::factory()->named('المدير العام')->create(['department_id' => $root->id]);
        $root->update(['manager_id' => $rootHead->id]);

        $departed = Employee::factory()->named('رئيس سابق')->create(['department_id' => $section->id]);
        $section->update(['manager_id' => $departed->id]);
        $departed->delete();

        $employee = Employee::factory()->create(['department_id' => $section->id]);

        $this->assertSame($rootHead->id, $this->chain->resolve($employee)?->id);
    }

    /**
     * A dissolved unit is a vacant post, not the end of the chain.
     *
     * Found in review, 2026-09-24: the walk started from `$employee->department`,
     * which the soft-delete scope resolves to null once the unit is deleted —
     * so staff still pointing at a dissolved office reported "no manager",
     * while GAP-3 (which walks trashed units on purpose) kept them in their
     * division head's scope. The office's own head is skipped: a deleted unit
     * holds no post.
     */
    public function test_staff_of_a_deleted_unit_resolve_to_the_live_manager_above_it(): void
    {
        [$root, $division, $section] = $this->tree();

        $office = Department::create([
            'name'      => 'مكتب الأرشيف',
            'code'      => 'HR-ARCH',
            'level'     => DepartmentLevel::cases()[3]->value,
            'parent_id' => $section->id,
        ]);

        $divisionHead = Employee::factory()->named('مدير الإدارة')->create(['department_id' => $division->id]);
        $division->update(['manager_id' => $divisionHead->id]);

        $officeHead = Employee::factory()->named('رئيس المكتب')->create(['department_id' => $division->id]);
        $office->update(['manager_id' => $officeHead->id]);

        $employee = Employee::factory()->create(['department_id' => $office->id]);
        $this->assertSame($officeHead->id, $this->chain->resolve($employee)?->id);

        $office->delete();

        $this->assertSame($divisionHead->id, $this->chain->resolve($employee->fresh())?->id);

        // With its last live child gone the section can be deleted too, and the
        // walk has to climb through a deleted ANCESTOR, not only a deleted
        // starting unit.
        $section->delete();

        $this->assertSame($divisionHead->id, $this->chain->resolve($employee->fresh())?->id);
    }

    public function test_the_api_names_that_manager_too(): void
    {
        $this->actingAsAdmin();

        [$root, $division, $section] = $this->tree();

        $divisionHead = Employee::factory()->named('مدير الإدارة')->create(['department_id' => $division->id]);
        $division->update(['manager_id' => $divisionHead->id]);

        $employee = Employee::factory()->create(['department_id' => $section->id]);
        $section->delete();

        $this->getJson("/api/hr/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.manager.id', $divisionHead->id);
    }

    // ── Rule 3 ───────────────────────────────────────────────────────────

    public function test_the_general_manager_has_nobody_above_them(): void
    {
        [$root] = $this->tree();

        $rootHead = Employee::factory()->named('المدير العام')->create(['department_id' => $root->id]);
        $root->update(['manager_id' => $rootHead->id]);

        $this->assertNull($this->chain->resolve($rootHead));
    }

    public function test_an_employee_with_no_unit_resolves_to_nobody_rather_than_failing(): void
    {
        $employee = Employee::factory()->create(['department_id' => null]);

        $this->assertNull($this->chain->resolve($employee));
    }

    // ── The API surface ──────────────────────────────────────────────────

    /**
     * The key exists on every render path. A path that forgets to attach the
     * relation renders `null` silently, which is indistinguishable from "this
     * person is the المدير العام" — so both paths are asserted.
     */
    public function test_the_detail_endpoint_carries_the_resolved_manager(): void
    {
        $this->actingAsAdmin();

        [$root, $division, $section] = $this->tree();

        $head = Employee::factory()->named('رئيس القسم')->create(['department_id' => $section->id]);
        $section->update(['manager_id' => $head->id]);

        $employee = Employee::factory()->create(['department_id' => $section->id]);

        $this->getJson("/api/hr/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.manager.id', $head->id)
            ->assertJsonPath('data.manager.full_name', 'رئيس القسم')
            // The stub carries the staff number because الاسم الثلاثي is not
            // unique at this headcount.
            ->assertJsonPath('data.manager.employee_number', $head->employee_number);
    }

    public function test_the_list_endpoint_carries_the_resolved_manager(): void
    {
        $this->actingAsAdmin();

        [$root, $division, $section] = $this->tree();

        $head = Employee::factory()->named('رئيس القسم')->create(['department_id' => $section->id]);
        $section->update(['manager_id' => $head->id]);

        Employee::factory()->named('موظف')->create(['department_id' => $section->id]);

        $row = collect($this->getJson('/api/hr/employees')->assertOk()->json('data'))
            ->firstWhere('full_name', 'موظف');

        $this->assertSame($head->id, $row['manager']['id']);
    }

    /**
     * C31: an embedded reference is null when there is nothing to reference,
     * and the key is never omitted — otherwise it would borrow C11's meaning,
     * which is "you are not allowed to see this".
     */
    public function test_the_key_is_present_and_null_at_the_top_of_the_tree(): void
    {
        $this->actingAsAdmin();

        [$root] = $this->tree();

        $rootHead = Employee::factory()->named('المدير العام')->create(['department_id' => $root->id]);
        $root->update(['manager_id' => $rootHead->id]);

        $data = $this->getJson("/api/hr/employees/{$rootHead->id}")->assertOk()->json('data');

        $this->assertArrayHasKey('manager', $data);
        $this->assertNull($data['manager']);
    }
}
