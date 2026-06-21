<?php

declare(strict_types=1);

namespace Tests\Feature\Departments;

use App\Modules\Auth\Models\User;
use App\Modules\Departments\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Department resource coverage.
 *
 * Exercises the full Route -> Controller -> DepartmentService -> Model ->
 * Resource path for the /api/departments endpoints through real HTTP
 * requests, including the auth:sanctum + audit + permission middleware stack.
 *
 * Authentication uses Sanctum::actingAs so the tests focus on the resource
 * behaviour rather than the login/token flow (covered by AuthTest). The
 * permission middleware is non-breaking until the RBAC package lands, so an
 * ordinary authenticated user is allowed through.
 */
class DepartmentTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_index_lists_departments(): void
    {
        $this->actingAsUser();
        Department::create(['name' => 'Engineering']);
        Department::create(['name' => 'Finance']);

        $this->getJson('/api/departments')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/departments')->assertUnauthorized();
    }

    public function test_store_creates_a_department(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/departments', ['name' => 'Engineering'])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Engineering');

        $this->assertDatabaseHas('departments', ['name' => 'Engineering']);
        // HasAuditLog records the creation.
        $this->assertDatabaseHas('audit_logs', ['event' => 'created']);
    }

    public function test_store_validates_name_is_required(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/departments', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_show_returns_a_department(): void
    {
        $this->actingAsUser();
        $department = Department::create(['name' => 'Engineering']);

        $this->getJson("/api/departments/{$department->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $department->id)
            ->assertJsonPath('data.name', 'Engineering');
    }

    public function test_update_modifies_a_department(): void
    {
        $this->actingAsUser();
        $department = Department::create(['name' => 'Engineering']);

        $this->putJson("/api/departments/{$department->id}", ['name' => 'R&D'])
            ->assertOk()
            ->assertJsonPath('data.name', 'R&D');

        $this->assertDatabaseHas('departments', ['id' => $department->id, 'name' => 'R&D']);
    }

    public function test_destroy_deletes_a_department(): void
    {
        $this->actingAsUser();
        $department = Department::create(['name' => 'Engineering']);

        $this->deleteJson("/api/departments/{$department->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('departments', ['id' => $department->id]);
    }
}
