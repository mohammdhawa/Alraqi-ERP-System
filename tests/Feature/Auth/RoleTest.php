<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Support\PermissionCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * RBAC coverage.
 *
 * Verifies User::hasPermission, the super-admin bypass, the roles CRUD/assign
 * endpoints, system-role protection, and — via the cache — that a permission
 * change actually reaches the users it affects. CheckPermission enforces: a user
 * reaches a permission-guarded route only when a role grants the permission (or
 * they are a super admin).
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

        // Grant a NORMAL (non-super) role carrying exactly one permission.
        $role = Role::create(['name' => 'dept-viewer']);
        $role->permissions()->sync(
            Permission::where('name', 'departments.view')->pluck('id'),
        );
        $user->roles()->attach($role->id);
        // Direct pivot writes bypass RoleService, so invalidate the cache here.
        PermissionCache::flush();

        $this->assertTrue($user->hasPermission('departments.view'));
        $this->assertFalse($user->hasPermission('hr.employees.view'));
        $this->assertFalse($user->hasPermission('nonexistent.permission'));
    }

    public function test_super_admin_bypasses_every_permission_check(): void
    {
        $this->seedRbac();
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', User::SUPER_ADMIN_ROLE)->value('id'));
        PermissionCache::flush();

        // The super_admin role carries no explicit permissions, yet the holder
        // passes ANY ability (that is what the Gate::before bypass means).
        $this->assertTrue($user->isSuperAdmin());
        $this->assertTrue($user->hasPermission('departments.view'));
        $this->assertTrue($user->hasPermission('anything.not.even.defined'));

        // And reaches a permission-guarded route end to end.
        Sanctum::actingAs($user);
        $this->getJson('/api/departments')->assertOk();
    }

    public function test_roles_index_lists_roles(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/auth/roles')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.name', 'super_admin')
            ->assertJsonPath('data.0.is_system', true);
    }

    public function test_create_role_with_permissions(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/auth/roles', [
            'name'        => 'hr-viewer',
            'label'       => 'مطالع الموارد البشرية',
            'description' => 'Read-only HR access',
            'permissions' => ['hr.employees.view', 'departments.view'],
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'hr-viewer')
            ->assertJsonPath('data.label', 'مطالع الموارد البشرية')
            ->assertJsonPath('data.is_system', false)
            ->assertJsonFragment(['hr.employees.view']);

        $this->assertDatabaseHas('roles', ['name' => 'hr-viewer', 'is_system' => false]);
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

        // 'super_admin' already exists (seeded); permission name is bogus.
        $this->postJson('/api/auth/roles', [
            'name'        => 'super_admin',
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
            Permission::where('name', 'hr.employees.view')->pluck('id')
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

    public function test_cannot_delete_a_system_role(): void
    {
        $this->actingAsAdmin();
        $systemRoleId = Role::where('name', User::SUPER_ADMIN_ROLE)->value('id');

        $this->deleteJson("/api/auth/roles/{$systemRoleId}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'لا يمكن تعديل أو حذف دور نظام.');

        $this->assertDatabaseHas('roles', ['id' => $systemRoleId, 'name' => 'super_admin']);
    }

    public function test_cannot_rename_a_system_role(): void
    {
        $this->actingAsAdmin();
        $systemRoleId = Role::where('name', User::SUPER_ADMIN_ROLE)->value('id');

        $this->putJson("/api/auth/roles/{$systemRoleId}", ['name' => 'root'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'لا يمكن تعديل أو حذف دور نظام.');

        $this->assertDatabaseHas('roles', ['id' => $systemRoleId, 'name' => 'super_admin']);
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
            ->assertJsonPath('message', 'ليس لديك الصلاحيات الكافية لتنفيذ هذا الإجراء.');
    }

    public function test_assign_role_grants_access_end_to_end(): void
    {
        // An admin performs the assignment.
        $this->actingAsAdmin();
        $superAdminRoleId = Role::where('name', User::SUPER_ADMIN_ROLE)->value('id');
        $target = User::factory()->create();

        $this->postJson('/api/auth/roles/assign', [
            'user_id' => $target->id,
            'role_id' => $superAdminRoleId,
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('user_roles', [
            'user_id' => $target->id,
            'role_id' => $superAdminRoleId,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'role_assigned']);

        // The target can now reach a permission-guarded route they previously
        // could not — proving CheckPermission enforces and the grant works.
        Sanctum::actingAs($target->fresh());
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

    public function test_unassign_role_revokes_access_end_to_end(): void
    {
        // An admin performs the revocation.
        $this->actingAsAdmin();
        $superAdminRoleId = Role::where('name', User::SUPER_ADMIN_ROLE)->value('id');
        $target = User::factory()->create();
        $target->roles()->attach($superAdminRoleId);
        PermissionCache::flush();

        // Sanity: the grant currently lets the target reach a guarded route.
        Sanctum::actingAs($target->fresh());
        $this->getJson('/api/departments')->assertOk();

        // Back to the admin to revoke.
        $this->actingAsAdmin();
        $this->postJson('/api/auth/roles/unassign', [
            'user_id' => $target->id,
            'role_id' => $superAdminRoleId,
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('user_roles', [
            'user_id' => $target->id,
            'role_id' => $superAdminRoleId,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'role_unassigned']);

        // The grant is gone: the target can no longer reach the guarded route.
        Sanctum::actingAs($target->fresh());
        $this->getJson('/api/departments')->assertForbidden();
    }

    public function test_removing_a_permission_from_a_role_invalidates_cached_access(): void
    {
        // A scoped role granting departments.view, assigned to a target user
        // through the API (so the permission cache is flushed).
        $this->actingAsAdmin();
        $role = Role::create(['name' => 'dept-viewer']);
        $role->permissions()->sync(Permission::where('name', 'departments.view')->pluck('id'));
        $target = User::factory()->create();

        $this->postJson('/api/auth/roles/assign', [
            'user_id' => $target->id,
            'role_id' => $role->id,
        ])->assertOk();

        // The target can read departments.
        Sanctum::actingAs($target->fresh());
        $this->getJson('/api/departments')->assertOk();

        // An admin strips the permission off the role via the API.
        $this->actingAsAdmin();
        $this->putJson("/api/auth/roles/{$role->id}", ['permissions' => []])->assertOk();

        // The target's previously cached access is invalidated: now forbidden.
        Sanctum::actingAs($target->fresh());
        $this->getJson('/api/departments')->assertForbidden();
    }

    public function test_unassign_role_is_idempotent_when_user_lacks_role(): void
    {
        // Revoking a role the user never held is a harmless no-op (still 200).
        $this->actingAsAdmin();
        $superAdminRoleId = Role::where('name', User::SUPER_ADMIN_ROLE)->value('id');
        $target = User::factory()->create();

        $this->postJson('/api/auth/roles/unassign', [
            'user_id' => $target->id,
            'role_id' => $superAdminRoleId,
        ])->assertOk();

        $this->assertDatabaseMissing('user_roles', [
            'user_id' => $target->id,
            'role_id' => $superAdminRoleId,
        ]);
    }

    public function test_unassign_role_forbidden_without_permission(): void
    {
        $this->seedRbac();
        $role = Role::create(['name' => 'temp']);
        $target = User::factory()->create();
        $target->roles()->attach($role->id);

        $this->actingAsRolelessUser();

        $this->postJson('/api/auth/roles/unassign', [
            'user_id' => $target->id,
            'role_id' => $role->id,
        ])->assertForbidden();
    }
}
