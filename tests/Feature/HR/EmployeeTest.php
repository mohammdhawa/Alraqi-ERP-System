<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\User;
use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Employee resource + User<->Employee link coverage.
 *
 * Exercises the /api/hr/employees endpoints through the full
 * Route -> Controller -> EmployeeService -> Model -> Resource path with the
 * auth:sanctum + audit + permission middleware stack, plus the relationship
 * wiring added in Package C.
 */
class EmployeeTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_employees(): void
    {
        $this->actingAsAdmin();
        Employee::create(['name' => 'Jane Doe']);
        Employee::create(['name' => 'John Roe']);

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
        // Authenticated, but no roles/permissions -> CheckPermission returns 403.
        $this->actingAsRolelessUser();

        $this->getJson('/api/hr/employees')
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'ليس لديك الصلاحيات الكافية لتنفيذ هذا الإجراء.');
    }

    public function test_store_creates_an_employee_in_a_department(): void
    {
        $this->actingAsAdmin();
        $department = Department::create([
            'name'  => 'Engineering',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ]);

        $this->postJson('/api/hr/employees', [
            'name'          => 'Jane Doe',
            'email'         => 'jane@example.com',
            'department_id' => $department->id,
            'job_title'     => 'Engineer',
            'hire_date'     => '2026-01-15',
            'salary'        => 85000,
            'status'        => 'active',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Jane Doe')
            ->assertJsonPath('data.department_id', $department->id)
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('employees', ['name' => 'Jane Doe', 'department_id' => $department->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'created']);
    }

    public function test_store_rejects_unknown_department(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/hr/employees', [
            'name'          => 'Jane Doe',
            'department_id' => 999,
        ])
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
        $division->delete(); // soft-deleted: still in the table, but not assignable

        $this->postJson('/api/hr/employees', [
            'name'          => 'Jane Doe',
            'department_id' => $division->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('department_id');
    }

    public function test_store_auto_assigns_an_employee_number(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/hr/employees', ['name' => 'Jane Doe'])
            ->assertCreated()
            ->assertJsonPath('data.employee_number', 'EMP-00001');

        // A second employee gets the next number — the value is not client-supplied.
        $this->postJson('/api/hr/employees', ['name' => 'John Roe'])
            ->assertCreated()
            ->assertJsonPath('data.employee_number', 'EMP-00002');
    }

    public function test_store_validates_status_enum(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/hr/employees', [
            'name'   => 'Jane Doe',
            'status' => 'retired',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_update_modifies_an_employee(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::create(['name' => 'Jane Doe', 'status' => 'active']);

        $this->putJson("/api/hr/employees/{$employee->id}", ['status' => 'terminated'])
            ->assertOk()
            ->assertJsonPath('data.status', 'terminated');

        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'status' => 'terminated']);
    }

    public function test_destroy_soft_deletes_an_employee(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::create(['name' => 'Jane Doe']);

        $this->deleteJson("/api/hr/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        // Employees are never hard-deleted: the row survives with deleted_at set
        // so users, departments (manager_id), and history stay resolvable.
        $this->assertSoftDeleted('employees', ['id' => $employee->id]);
        $this->assertNull(Employee::find($employee->id));
    }

    /**
     * A read-only role (only hr.employees.view) may list/show but must NOT be
     * able to create, update, or delete. Guards against the per-action
     * permissions regressing back to a blanket `view` on every route.
     */
    public function test_view_only_user_cannot_write(): void
    {
        $this->actingAsUserWithPermissions(['hr.employees.view']);
        $employee = Employee::create(['name' => 'Jane Doe']);

        // Reads are allowed.
        $this->getJson('/api/hr/employees')->assertOk();
        $this->getJson("/api/hr/employees/{$employee->id}")->assertOk();

        // Writes are forbidden without the matching permission.
        $this->postJson('/api/hr/employees', ['name' => 'New Hire'])->assertForbidden();
        $this->putJson("/api/hr/employees/{$employee->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->deleteJson("/api/hr/employees/{$employee->id}")->assertForbidden();

        // Nothing was mutated.
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'name' => 'Jane Doe']);
        $this->assertDatabaseMissing('employees', ['name' => 'New Hire']);
    }

    public function test_user_links_to_employee_and_back(): void
    {
        $department = Department::create([
            'name'  => 'Engineering',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ]);
        $employee = Employee::create(['name' => 'Jane Doe', 'department_id' => $department->id]);
        $user = User::factory()->create(['employee_id' => $employee->id]);

        // belongsTo: user -> employee
        $this->assertTrue($user->employee->is($employee));
        // hasOne inverse: employee -> user
        $this->assertTrue($employee->refresh()->user->is($user));
        // employee -> department
        $this->assertTrue($employee->department->is($department));
    }

    public function test_department_manager_and_members_resolve(): void
    {
        $department = Department::create([
            'name'  => 'Engineering',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ]);
        $manager = Employee::create(['name' => 'Sara', 'department_id' => $department->id]);
        $member  = Employee::create(['name' => 'Omar', 'department_id' => $department->id]);
        $department->update(['manager_id' => $manager->id]);

        $department->refresh();

        // department -> manager (belongsTo via manager_id)
        $this->assertTrue($department->manager->is($manager));
        // department -> employees (hasMany via department_id): both staff
        $this->assertCount(2, $department->employees);
        // full chain: a member -> their department -> its manager
        $this->assertTrue($member->department->manager->is($manager));
    }
}
