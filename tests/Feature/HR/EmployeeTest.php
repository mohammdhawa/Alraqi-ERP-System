<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\User;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
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

    private function actingAsUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_index_lists_employees(): void
    {
        $this->actingAsUser();
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

    public function test_store_creates_an_employee_in_a_department(): void
    {
        $this->actingAsUser();
        $department = Department::create(['name' => 'Engineering']);

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
        $this->actingAsUser();

        $this->postJson('/api/hr/employees', [
            'name'          => 'Jane Doe',
            'department_id' => 999,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('department_id');
    }

    public function test_store_validates_status_enum(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/hr/employees', [
            'name'   => 'Jane Doe',
            'status' => 'retired',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_update_modifies_an_employee(): void
    {
        $this->actingAsUser();
        $employee = Employee::create(['name' => 'Jane Doe', 'status' => 'active']);

        $this->putJson("/api/hr/employees/{$employee->id}", ['status' => 'terminated'])
            ->assertOk()
            ->assertJsonPath('data.status', 'terminated');

        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'status' => 'terminated']);
    }

    public function test_destroy_deletes_an_employee(): void
    {
        $this->actingAsUser();
        $employee = Employee::create(['name' => 'Jane Doe']);

        $this->deleteJson("/api/hr/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('employees', ['id' => $employee->id]);
    }

    public function test_user_links_to_employee_and_back(): void
    {
        $department = Department::create(['name' => 'Engineering']);
        $employee = Employee::create(['name' => 'Jane Doe', 'department_id' => $department->id]);
        $user = User::factory()->create(['employee_id' => $employee->id]);

        // belongsTo: user -> employee
        $this->assertTrue($user->employee->is($employee));
        // hasOne inverse: employee -> user
        $this->assertTrue($employee->refresh()->user->is($user));
        // employee -> department
        $this->assertTrue($employee->department->is($department));
    }
}
