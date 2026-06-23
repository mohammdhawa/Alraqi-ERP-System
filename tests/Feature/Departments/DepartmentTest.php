<?php

declare(strict_types=1);

namespace Tests\Feature\Departments;

use App\Modules\Departments\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Department resource coverage.
 *
 * Exercises the full Route -> Controller -> DepartmentService -> Model ->
 * Resource path for the /api/departments endpoints through real HTTP
 * requests, including the auth:sanctum + audit + permission middleware stack.
 *
 * Authentication uses an admin user (actingAsAdmin) because the resource is
 * guarded by permission:departments.view, which is now enforced by RBAC
 * (Package D). A separate test covers the 403 for a user without permission.
 */
class DepartmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_departments(): void
    {
        $this->actingAsAdmin();
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

    public function test_index_forbidden_without_permission(): void
    {
        // Authenticated, but no roles/permissions -> CheckPermission returns 403.
        $this->actingAsRolelessUser();

        $this->getJson('/api/departments')
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'ليس لديك الصلاحيات الكافية لتنفيذ هذا الإجراء.');
    }

    public function test_store_creates_a_department(): void
    {
        $this->actingAsAdmin();

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
        $this->actingAsAdmin();

        $this->postJson('/api/departments', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_show_returns_a_department(): void
    {
        $this->actingAsAdmin();
        $department = Department::create(['name' => 'Engineering']);

        $this->getJson("/api/departments/{$department->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $department->id)
            ->assertJsonPath('data.name', 'Engineering');
    }

    public function test_update_modifies_a_department(): void
    {
        $this->actingAsAdmin();
        $department = Department::create(['name' => 'Engineering']);

        $this->putJson("/api/departments/{$department->id}", ['name' => 'R&D'])
            ->assertOk()
            ->assertJsonPath('data.name', 'R&D');

        $this->assertDatabaseHas('departments', ['id' => $department->id, 'name' => 'R&D']);
    }

    public function test_destroy_deletes_a_department(): void
    {
        $this->actingAsAdmin();
        $department = Department::create(['name' => 'Engineering']);

        $this->deleteJson("/api/departments/{$department->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('departments', ['id' => $department->id]);
    }

    /**
     * A read-only role (only departments.view) may list/show but must NOT be
     * able to create, update, or delete. Guards against the per-action
     * permissions regressing back to a blanket `view` on every route.
     */
    public function test_view_only_user_cannot_write(): void
    {
        $this->actingAsUserWithPermissions(['departments.view']);
        $department = Department::create(['name' => 'Engineering']);

        // Reads are allowed.
        $this->getJson('/api/departments')->assertOk();
        $this->getJson("/api/departments/{$department->id}")->assertOk();

        // Writes are forbidden without the matching permission.
        $this->postJson('/api/departments', ['name' => 'New'])->assertForbidden();
        $this->putJson("/api/departments/{$department->id}", ['name' => 'R&D'])->assertForbidden();
        $this->deleteJson("/api/departments/{$department->id}")->assertForbidden();

        // Nothing was mutated.
        $this->assertDatabaseHas('departments', ['id' => $department->id, 'name' => 'Engineering']);
        $this->assertDatabaseMissing('departments', ['name' => 'New']);
    }
}
