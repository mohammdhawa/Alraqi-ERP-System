<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * RBAC coverage (Package D).
 *
 * Verifies User::hasPermission, the roles read/assign endpoints, and — most
 * importantly — that CheckPermission now actually enforces: a user gains access
 * to a permission-guarded route only after being granted a role that carries
 * the permission.
 */
class RoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_has_permission_reflects_granted_roles(): void
    {
        $this->seedRbac();
        $user = User::factory()->create();

        // No roles yet.
        $this->assertFalse($user->hasPermission('departments.view'));

        $user->roles()->attach(Role::where('name', 'admin')->value('id'));

        $this->assertTrue($user->fresh()->hasPermission('departments.view'));
        $this->assertTrue($user->fresh()->hasPermission('hr.employees.view'));
        $this->assertFalse($user->fresh()->hasPermission('nonexistent.permission'));
    }

    public function test_roles_index_lists_roles_with_permissions(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/auth/roles')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.name', 'admin')
            ->assertJsonFragment(['departments.view']);
    }

    public function test_create_role_with_permissions(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/auth/roles', [
            'name'        => 'hr-viewer',
            'description' => 'Read-only HR access',
            'permissions' => ['hr.employees.view', 'departments.view'],
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'hr-viewer')
            ->assertJsonFragment(['hr.employees.view']);

        $this->assertDatabaseHas('roles', ['name' => 'hr-viewer']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'role_created']);

        // The new role carries exactly the two requested permissions.
        $role = Role::where('name', 'hr-viewer')->first();
        $this->assertEqualsCanonicalizing(
            ['hr.employees.view', 'departments.view'],
            $role->permissions->pluck('name')->all(),
        );
    }

    public function test_create_role_rejects_duplicate_name_and_unknown_permission(): void
    {
        $this->actingAsAdmin();

        // 'admin' already exists (seeded); permission name is bogus.
        $this->postJson('/api/auth/roles', [
            'name'        => 'admin',
            'permissions' => ['does.not.exist'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'permissions.0']);
    }

    public function test_create_role_forbidden_without_permission(): void
    {
        $this->actingAsRolelessUser();

        $this->postJson('/api/auth/roles', ['name' => 'whatever'])
            ->assertForbidden();
    }

    public function test_update_role_name_and_permissions(): void
    {
        $this->actingAsAdmin();
        $role = Role::create(['name' => 'hr-viewer']);
        $role->permissions()->sync(
            \App\Modules\Auth\Models\Permission::where('name', 'hr.employees.view')->pluck('id')
        );

        $this->putJson("/api/auth/roles/{$role->id}", [
            'name'        => 'hr-reader',
            'permissions' => ['departments.view'], // replaces the set
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'hr-reader')
            ->assertJsonFragment(['departments.view']);

        $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => 'hr-reader']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'role_updated']);

        // sync replaced permissions: only departments.view remains.
        $this->assertEqualsCanonicalizing(
            ['departments.view'],
            $role->fresh()->permissions->pluck('name')->all(),
        );
    }

    public function test_delete_role_removes_it_and_detaches_users(): void
    {
        $admin = $this->actingAsAdmin();
        $role = Role::create(['name' => 'temp']);
        $admin->roles()->attach($role->id);

        $this->deleteJson("/api/auth/roles/{$role->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
        // Pivot cascades on delete.
        $this->assertDatabaseMissing('user_roles', ['role_id' => $role->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'role_deleted']);
    }

    public function test_cannot_delete_admin_role(): void
    {
        $this->actingAsAdmin();
        $adminRoleId = Role::where('name', 'admin')->value('id');

        $this->deleteJson("/api/auth/roles/{$adminRoleId}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'The admin role cannot be deleted.');

        $this->assertDatabaseHas('roles', ['id' => $adminRoleId, 'name' => 'admin']);
    }

    public function test_update_and_delete_forbidden_without_permission(): void
    {
        $this->seedRbac();
        $role = Role::create(['name' => 'temp']);

        $this->actingAsRolelessUser();

        $this->putJson("/api/auth/roles/{$role->id}", ['name' => 'x'])->assertForbidden();
        $this->deleteJson("/api/auth/roles/{$role->id}")->assertForbidden();
    }

    public function test_roles_index_forbidden_without_permission(): void
    {
        $this->actingAsRolelessUser();

        $this->getJson('/api/auth/roles')
            ->assertForbidden()
            ->assertJsonPath('message', 'Insufficient permissions');
    }

    public function test_assign_role_grants_access_end_to_end(): void
    {
        // An admin performs the assignment.
        $this->actingAsAdmin();
        $adminRoleId = Role::where('name', 'admin')->value('id');
        $target = User::factory()->create();

        $this->postJson('/api/auth/roles/assign', [
            'user_id' => $target->id,
            'role_id' => $adminRoleId,
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('user_roles', [
            'user_id' => $target->id,
            'role_id' => $adminRoleId,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'role_assigned']);

        // The target can now reach a permission-guarded route they previously
        // could not — proving CheckPermission enforces and the grant works.
        Sanctum::actingAs($target);
        $this->getJson('/api/departments')->assertOk();
    }

    public function test_assign_role_validates_inputs(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/auth/roles/assign', [
            'user_id' => 999999,
            'role_id' => 999999,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id', 'role_id']);
    }
}
