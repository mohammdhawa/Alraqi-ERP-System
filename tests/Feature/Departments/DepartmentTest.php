<?php

declare(strict_types=1);

namespace Tests\Feature\Departments;

use App\Modules\Departments\Enums\DepartmentLevel;
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
        $root = Department::create([
            'name'  => 'الإدارة العامة',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ]);
        Department::create([
            'name'      => 'الإدارة الهندسية',
            'parent_id' => $root->id,
            'level'     => DepartmentLevel::Division->value,
        ]);

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

        $this->postJson('/api/departments', [
            'name'  => 'الإدارة العامة',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'الإدارة العامة')
            ->assertJsonPath('data.level', 1)
            ->assertJsonPath('data.level_label', 'الإدارة العامة');

        $this->assertDatabaseHas('departments', ['name' => 'الإدارة العامة', 'level' => 1]);
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
        $department = Department::create([
            'name'  => 'Engineering',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ]);

        $this->getJson("/api/departments/{$department->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $department->id)
            ->assertJsonPath('data.name', 'Engineering');
    }

    public function test_update_modifies_a_department(): void
    {
        $this->actingAsAdmin();
        $department = Department::create([
            'name'  => 'Engineering',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ]);

        $this->putJson("/api/departments/{$department->id}", ['name' => 'R&D'])
            ->assertOk()
            ->assertJsonPath('data.name', 'R&D');

        $this->assertDatabaseHas('departments', ['id' => $department->id, 'name' => 'R&D']);
    }

    /**
     * Departments soft-delete: the row survives with deleted_at set so employees
     * and audit entries pointing at it stay resolvable. Asserting
     * assertDatabaseMissing here would be asserting data loss.
     *
     * A non-root, childless unit (a division under the root) is targeted — the
     * root itself is undeletable, and a unit with live children is blocked.
     */
    public function test_destroy_soft_deletes_a_department(): void
    {
        $this->actingAsAdmin();
        $root = Department::create([
            'name'  => 'الإدارة العامة',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ]);
        $department = Department::create([
            'name'      => 'الإدارة الهندسية',
            'parent_id' => $root->id,
            'level'     => DepartmentLevel::Division->value,
        ]);

        $this->deleteJson("/api/departments/{$department->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('departments', ['id' => $department->id]);
        $this->assertNull(Department::find($department->id));
    }

    /**
     * A read-only role (only departments.view) may list/show but must NOT be
     * able to create, update, or delete. Guards against the per-action
     * permissions regressing back to a blanket `view` on every route.
     */
    public function test_view_only_user_cannot_write(): void
    {
        $this->actingAsUserWithPermissions(['departments.view']);
        $department = Department::create([
            'name'  => 'Engineering',
            'level' => DepartmentLevel::GeneralAdministration->value,
        ]);

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
