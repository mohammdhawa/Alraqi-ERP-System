<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\User;
use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Enums\Gender;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\JobTitle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Employee resource coverage, through the full
 * Route -> Controller -> EmployeeService -> Model -> Resource path with the
 * auth:sanctum + audit + permission middleware stack.
 *
 * Rewritten for the Batch 2 shape: `name` is three columns, `job_title` is an
 * FK, `salary` is gone, and `status` is an int-backed enum.
 */
class EmployeeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A complete, valid create payload. Tests that care about ONE field
     * override it, so a test's diff from this array is exactly what it is
     * about.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name'  => 'جين',
            'middle_name' => 'أحمد',
            'last_name'   => 'دو',
            'gender'      => Gender::Female->value,
        ], $overrides);
    }

    public function test_index_lists_employees(): void
    {
        $this->actingAsAdmin();
        Employee::factory()->named('Jane Doe')->create();
        Employee::factory()->named('John Roe')->create();

        $this->getJson('/api/hr/employees')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/hr/employees')->assertUnauthorized();
    }

    public function test_index_forbidden_without_permission(): void
    {
        $this->actingAsRolelessUser();

        $this->getJson('/api/hr/employees')
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'ليس لديك الصلاحيات الكافية لتنفيذ هذا الإجراء.');
    }

    public function test_store_creates_an_employee_with_a_split_name(): void
    {
        $this->actingAsAdmin();
        $department = Department::create([
            'name'  => 'الإدارة العامة',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ]);
        $title = JobTitle::create(['name' => 'مهندس']);

        $this->postJson('/api/hr/employees', $this->payload([
            'email'         => 'jane@example.com',
            'department_id' => $department->id,
            'job_title_id'  => $title->id,
            'hire_date'     => '2026-01-15',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.first_name', 'جين')
            ->assertJsonPath('data.last_name', 'دو')
            // The joined accessor ships alongside the parts, so a client
            // rendering a header never re-joins them itself.
            ->assertJsonPath('data.full_name', 'جين أحمد دو')
            ->assertJsonPath('data.department_id', $department->id)
            ->assertJsonPath('data.job_title_id', $title->id)
            ->assertJsonPath('data.status', EmployeeStatus::Active->value)
            ->assertJsonPath('data.status_label', 'نشط');

        $this->assertDatabaseHas('employees', [
            'first_name'    => 'جين',
            'last_name'     => 'دو',
            'department_id' => $department->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'created']);
    }

    /**
     * `name` is gone. A client still posting it must not appear to succeed —
     * the name parts are required, so the request fails on those rather than
     * silently creating a nameless employee.
     */
    public function test_store_rejects_the_old_name_field(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/hr/employees', ['name' => 'Jane Doe'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name', 'middle_name', 'last_name', 'gender']);

        $this->assertDatabaseCount('employees', 0);
    }

    public function test_store_rejects_unknown_department(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/hr/employees', $this->payload(['department_id' => 999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('department_id');
    }

    public function test_store_rejects_a_soft_deleted_department(): void
    {
        $this->actingAsAdmin();
        $root = Department::create([
            'name'  => 'الإدارة العامة',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ]);
        $division = Department::create([
            'name'      => 'الإدارة الهندسية',
            'parent_id' => $root->id,
            'level'     => DepartmentLevel::Division->value,
        ]);
        $division->delete();

        $this->postJson('/api/hr/employees', $this->payload(['department_id' => $division->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('department_id');
    }

    public function test_store_auto_assigns_an_employee_number(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/hr/employees', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.employee_number', 'EMP-00001');

        $this->postJson('/api/hr/employees', $this->payload(['first_name' => 'جون']))
            ->assertCreated()
            ->assertJsonPath('data.employee_number', 'EMP-00002');
    }

    /**
     * HR may type a staff number by hand — to continue the company's own
     * M024333 series, or to correct one. Only uniqueness is enforced.
     */
    public function test_a_supplied_employee_number_is_respected(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/hr/employees', $this->payload(['employee_number' => 'M024333']))
            ->assertCreated()
            ->assertJsonPath('data.employee_number', 'M024333');

        $this->postJson('/api/hr/employees', $this->payload(['employee_number' => 'M024333']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employee_number');
    }

    /**
     * The generator derives its candidate from max(id), so a manually assigned
     * number can sit exactly where the next generated one would land. Without
     * the collision loop this is a raw integrity error — a 500 on an ordinary
     * create.
     */
    public function test_the_generator_steps_past_a_manually_taken_number(): void
    {
        $this->actingAsAdmin();

        // Employee #1 manually claims the number #2 would otherwise generate.
        Employee::factory()->named('First')->create(['employee_number' => 'EMP-00002']);

        $this->postJson('/api/hr/employees', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.employee_number', 'EMP-00003');
    }

    public function test_store_validates_the_status_enum(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/hr/employees', $this->payload(['status' => 99]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_update_modifies_an_employee(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->named('Jane Doe')->create();

        $this->putJson("/api/hr/employees/{$employee->id}", [
            'status' => EmployeeStatus::Terminated->value,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', EmployeeStatus::Terminated->value)
            ->assertJsonPath('data.status_label', 'منتهي الخدمة');

        $this->assertDatabaseHas('employees', [
            'id'     => $employee->id,
            'status' => EmployeeStatus::Terminated->value,
        ]);
    }

    public function test_destroy_soft_deletes_an_employee(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->named('Jane Doe')->create();

        $this->deleteJson("/api/hr/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        // Employees are never hard-deleted: users, departments (manager_id) and
        // history keep pointing at them.
        $this->assertSoftDeleted('employees', ['id' => $employee->id]);
        $this->assertNull(Employee::find($employee->id));
    }

    /**
     * §10 test 4 — the one people skip, and the one that catches a write route
     * guarded by `.view`.
     */
    public function test_view_only_user_cannot_write(): void
    {
        $this->actingAsUserWithPermissions(['hr.employees.view']);
        $employee = Employee::factory()->named('Jane Doe')->create();

        $this->getJson('/api/hr/employees')->assertOk();
        $this->getJson("/api/hr/employees/{$employee->id}")->assertOk();

        $this->postJson('/api/hr/employees', $this->payload())->assertForbidden();
        $this->putJson("/api/hr/employees/{$employee->id}", ['first_name' => 'Renamed'])->assertForbidden();
        $this->deleteJson("/api/hr/employees/{$employee->id}")->assertForbidden();

        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'first_name' => 'Jane']);
        $this->assertDatabaseMissing('employees', ['first_name' => 'جين']);
    }

    // ── Filtering, search and sorting (C30) ──────────────────────────────

    public function test_index_filters_by_status(): void
    {
        $this->actingAsAdmin();
        Employee::factory()->named('Active Person')->create();
        Employee::factory()->named('Gone Person')->create(['status' => EmployeeStatus::Terminated]);

        $this->getJson('/api/hr/employees?status='.EmployeeStatus::Terminated->value)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'Gone');
    }

    /**
     * Searching the PARTS is what makes a father's name findable — the middle
     * name is spoken as part of the name here, so people are looked up by it.
     */
    public function test_index_searches_every_name_part_and_both_numbers(): void
    {
        $this->actingAsAdmin();
        Employee::factory()->named('سامر خالد الحلبي')->create(['legacy_number' => 'M024333']);
        Employee::factory()->named('نور علي الشامي')->create();

        $this->getJson('/api/hr/employees?search=خالد')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'سامر خالد الحلبي');

        $this->getJson('/api/hr/employees?search=M024333')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    /**
     * A search must not escape an active filter and turn
     * `status=X&search=Y` into "status X OR anyone called Y".
     */
    public function test_search_is_grouped_so_it_cannot_escape_a_filter(): void
    {
        $this->actingAsAdmin();
        Employee::factory()->named('سامر الحلبي')->create(['status' => EmployeeStatus::Terminated]);
        Employee::factory()->named('سامر الشامي')->create();

        $this->getJson('/api/hr/employees?status='.EmployeeStatus::Active->value.'&search=سامر')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'سامر الشامي');
    }

    /**
     * An unknown sort is a 422, never a silent fallback: quietly ignoring it
     * returns a correct-looking page in the wrong order and the caller cannot
     * tell (C30, P3).
     */
    public function test_an_unknown_sort_is_rejected_rather_than_ignored(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/hr/employees?sort=salary')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sort');

        $this->getJson('/api/hr/employees?sort=-last_name')->assertOk();
    }

    // ── Relationships ────────────────────────────────────────────────────

    public function test_user_links_to_employee_and_back(): void
    {
        $department = Department::create([
            'name'  => 'الإدارة العامة',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ]);
        $employee = Employee::factory()->named('Jane Doe')->create(['department_id' => $department->id]);
        $user     = User::factory()->create(['employee_id' => $employee->id]);

        $this->assertTrue($user->employee->is($employee));
        $this->assertTrue($employee->refresh()->user->is($user));
        $this->assertTrue($employee->department->is($department));
    }

    public function test_department_manager_and_members_resolve(): void
    {
        $department = Department::create([
            'name'  => 'الإدارة العامة',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ]);
        $manager = Employee::factory()->named('Sara')->create(['department_id' => $department->id]);
        $member  = Employee::factory()->named('Omar')->create(['department_id' => $department->id]);
        $department->update(['manager_id' => $manager->id]);

        $department->refresh();

        $this->assertTrue($department->manager->is($manager));
        $this->assertCount(2, $department->employees);
        $this->assertTrue($member->department->manager->is($manager));
    }

    /**
     * NULL on direct_manager_id means "resolve from the org tree", so an
     * employee may not be their own exception to it.
     */
    public function test_an_employee_cannot_be_their_own_direct_manager(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->named('Jane Doe')->create();

        $this->putJson("/api/hr/employees/{$employee->id}", [
            'direct_manager_id' => $employee->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('direct_manager_id');
    }

    /**
     * `direct_manager_id` keeps its meaning and its place — it is what a client
     * WRITES — and `direct_manager` is the same person as an object so nothing
     * has to resolve the id with a second request (C31).
     *
     * The stub carries `employee_number` because الاسم الثلاثي is not unique at
     * this headcount; the staff number is what distinguishes two managers who
     * share a name.
     */
    public function test_show_embeds_the_direct_manager(): void
    {
        $this->actingAsAdmin();
        $manager  = Employee::factory()->named('سامر خليل')->create();
        $employee = Employee::factory()->named('جين دو')->create(['direct_manager_id' => $manager->id]);

        $this->getJson("/api/hr/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.direct_manager_id', $manager->id)
            ->assertJsonPath('data.direct_manager.id', $manager->id)
            ->assertJsonPath('data.direct_manager.full_name', 'سامر خليل')
            ->assertJsonPath('data.direct_manager.employee_number', $manager->employee_number);
    }

    /**
     * The usual case: nobody manages this person BY EXCEPTION, so the id is
     * null and so is the object.
     *
     * ⚠️ NULL, NOT ABSENT. A missing key in this API means "you may not see
     * this field" (C11 gates `national_id` and the pay block exactly that way),
     * so an embed that disappeared when there is nobody to embed would be
     * reporting a permission problem that does not exist.
     */
    public function test_direct_manager_is_null_rather_than_absent_when_there_is_none(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->named('جين دو')->create();

        $data = $this->getJson("/api/hr/employees/{$employee->id}")->assertOk()->json('data');

        $this->assertNull($data['direct_manager_id']);
        $this->assertArrayHasKey('direct_manager', $data, 'the embed must be present even with nobody to embed');
        $this->assertNull($data['direct_manager']);
    }

    /**
     * The update response reflects the manager just assigned, not the one the
     * bound model was holding — the controller reloads the relation after the
     * write rather than keeping whatever was already there.
     */
    public function test_updating_the_direct_manager_updates_the_embedded_object(): void
    {
        $this->actingAsAdmin();
        $first    = Employee::factory()->named('المدير الأول')->create();
        $second   = Employee::factory()->named('المدير الثاني')->create();
        $employee = Employee::factory()->named('جين دو')->create(['direct_manager_id' => $first->id]);

        $this->putJson("/api/hr/employees/{$employee->id}", ['direct_manager_id' => $second->id])
            ->assertOk()
            ->assertJsonPath('data.direct_manager_id', $second->id)
            ->assertJsonPath('data.direct_manager.id', $second->id)
            ->assertJsonPath('data.direct_manager.full_name', 'المدير الثاني');
    }

    /**
     * The list embeds managers too, and resolves them in ONE query for the
     * whole page.
     *
     * Without the service's `directManager` eager-load this is a query per row
     * that has one — the classic N+1 that stays invisible while tests create
     * two employees and is a page of extra queries against the real staff.
     *
     * The count is exact and the arithmetic is: 1 pagination count + 1 page
     * select + 1 eager-load of the managers = 3 queries against `employees`.
     * Lazily resolved, the third becomes one per managed row (5 here).
     */
    public function test_the_index_embeds_direct_managers_without_an_n_plus_one(): void
    {
        $this->actingAsAdmin();

        foreach (range(1, 3) as $i) {
            $manager = Employee::factory()->named("مدير {$i}")->create();
            Employee::factory()->named("تابع {$i}")->create(['direct_manager_id' => $manager->id]);
        }

        $queries = [];
        \DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'from "employees"')) {
                $queries[] = $query->sql;
            }
        });

        $this->getJson('/api/hr/employees')->assertOk();

        $this->assertCount(
            3,
            $queries,
            'expected count + page + one managers query; got '.count($queries)
                .' — direct managers are probably being resolved per row again',
        );
    }

    /**
     * Sons and daughters are the stored facts; the total is derived, so it
     * cannot disagree with them.
     */
    public function test_children_count_is_derived_from_sons_and_daughters(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/hr/employees', $this->payload([
            'sons_count'      => 2,
            'daughters_count' => 3,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.children_count', 5);

        // Derived means derived: there is no column holding the total, so it
        // cannot go stale against the two parts (P1).
        $this->assertFalse(Schema::hasColumn('employees', 'children_count'));
    }
}
