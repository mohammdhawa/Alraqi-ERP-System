<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\RoleService;
use App\Modules\Auth\Support\PermissionCache;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Model;
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

    public function test_delete_role_soft_deletes_it_and_revokes_its_access(): void
    {
        $this->actingAsAdmin();
        $role   = Role::create(['name' => 'temp']);
        $target = User::factory()->create();
        $role->permissions()->sync(Permission::where('name', 'departments.view')->pluck('id'));
        $target->roles()->attach($role->id);
        PermissionCache::flush();

        $this->deleteJson("/api/auth/roles/{$role->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('roles', ['id' => $role->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'role_deleted']);

        // The pivot SURVIVES now — a soft delete fires no cascade — so this is
        // the assertion that matters: the grant must stop working anyway. The
        // SoftDeletes global scope applies through the belongsToMany, so a
        // trashed role contributes no permissions to its former holders.
        $this->assertDatabaseHas('user_roles', ['role_id' => $role->id, 'user_id' => $target->id]);
        $this->assertCount(0, $target->fresh()->roles);

        Sanctum::actingAs($target->fresh());
        $this->getJson('/api/departments')->assertForbidden();
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

    /**
     * §10 test 2 — was missing for this resource.
     */
    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/auth/roles')->assertUnauthorized();
    }

    /**
     * §10 test 4 — a caller holding only auth.roles.view must not be able to
     * create, edit, delete, or grant a role. Assign/unassign matter most here:
     * they are guarded by auth.roles.assign (auth.roles.update until GAP-15),
     * so a read-only role must not be able to hand ITSELF a better one.
     */
    public function test_a_read_only_user_cannot_write_or_grant(): void
    {
        $role   = Role::create(['name' => 'temp']);
        $target = User::factory()->create();

        $this->actingAsUserWithPermissions(['auth.roles.view']);

        $this->getJson('/api/auth/roles')->assertOk();

        $this->postJson('/api/auth/roles', ['name' => 'invented'])->assertForbidden();
        $this->putJson("/api/auth/roles/{$role->id}", ['name' => 'renamed'])->assertForbidden();
        $this->deleteJson("/api/auth/roles/{$role->id}")->assertForbidden();

        $this->postJson('/api/auth/roles/assign', [
            'user_id' => $target->id,
            'role_id' => $role->id,
        ])->assertForbidden();

        $this->postJson('/api/auth/roles/unassign', [
            'user_id' => $target->id,
            'role_id' => $role->id,
        ])->assertForbidden();
    }

    /**
     * GAP-15 split one permission into two, and the routes are the split: a
     * company-wide `auth.roles.update` no longer assigns, and
     * `auth.roles.assign` does not edit a role. Without this, reverting the
     * assign middleware to `update` — or accepting both — passed every test.
     */
    public function test_editing_roles_and_assigning_them_are_separate_permissions(): void
    {
        $role   = Role::create(['name' => 'temp']);
        $target = User::factory()->create();
        $grant  = ['user_id' => $target->id, 'role_id' => $role->id];

        $this->actingAsUserWithPermissions(['auth.roles.update']);

        $this->putJson("/api/auth/roles/{$role->id}", ['name' => 'renamed'])->assertOk();
        $this->postJson('/api/auth/roles/assign', $grant)->assertForbidden();
        $this->postJson('/api/auth/roles/unassign', $grant)->assertForbidden();

        $this->actingAsUserWithPermissions(['auth.roles.assign']);

        $this->putJson("/api/auth/roles/{$role->id}", ['name' => 'renamed-again'])->assertForbidden();
        $this->postJson('/api/auth/roles/assign', $grant)->assertOk();
        $this->postJson('/api/auth/roles/unassign', $grant)->assertOk();

        $this->assertSame('renamed', $role->fresh()->name);
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

    /**
     * The assign/unassign response must not lazy-load during serialization.
     *
     * `UserResource` resolves the account's display name through `employee` (a
     * user row has no name of its own) and dereferences it directly rather than
     * via whenLoaded. `RoleService` returned only `roles`, so wrapping the
     * result in a Resource fired an extra SELECT against `employees` inside the
     * response layer — a query in the one layer whose contract forbids them.
     * It is a single row here, but the same shape on a list endpoint is an N+1.
     *
     * ⚠️ THIS ASSERTS relationLoaded, NOT A QUERY COUNT. The first version
     * counted queries touching `employees` and asserted exactly one — which
     * does not discriminate at all, because the eager load and the lazy load
     * each issue exactly one. It passed against the broken implementation.
     * Counting the queries a correct implementation happens to make says
     * nothing about whether it is correct; assert the property you require.
     */
    public function test_assign_and_unassign_return_a_user_ready_to_serialize(): void
    {
        $employee = Employee::factory()->named('اسم المستخدم')->create();
        $target   = User::factory()->create(['employee_id' => $employee->id]);
        $role     = Role::create(['name' => 'temp']);
        $service  = app(RoleService::class);

        // Both methods, not just assign — the original test claimed to cover
        // assign/unassign and exercised only one of them.
        foreach (['assign', 'unassign'] as $method) {
            $user = $service->{$method}((int) $target->id, (int) $role->id);

            $this->assertTrue(
                $user->relationLoaded('roles'),
                "RoleService::{$method}() returned a user with `roles` unloaded",
            );
            $this->assertTrue(
                $user->relationLoaded('employee'),
                "RoleService::{$method}() returned a user with `employee` unloaded — "
                    .'UserResource lazy-loads it during serialization',
            );
        }
    }

    /**
     * The HTTP-level shape of the same guarantee: the name still resolves.
     *
     * ⚠️ DO NOT "STRENGTHEN" THIS WITH Model::preventLazyLoading(). It cannot
     * catch this bug, and a version of this test that used it passed against the
     * broken implementation. Builder::hydrate() assigns the per-instance
     * `preventsLazyLoading` flag ONLY when `count($items) > 1` — Laravel
     * deliberately exempts single-model results, on the reasoning that one model
     * cannot cause an N+1. Every Resource-layer lazy load in this codebase is on
     * a single-model path (findOrFail, route-model binding), which is precisely
     * the case the framework's strictness does not cover.
     *
     * The real guard is the relationLoaded assertion above.
     */
    public function test_assign_response_still_resolves_the_display_name(): void
    {
        $this->actingAsAdmin();
        $employee = Employee::factory()->named('اسم المستخدم')->create();
        $target   = User::factory()->create(['employee_id' => $employee->id]);
        $roleId   = Role::where('name', User::SUPER_ADMIN_ROLE)->value('id');

        $this->postJson('/api/auth/roles/assign', [
            'user_id' => $target->id,
            'role_id' => $roleId,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'اسم المستخدم')
            ->assertJsonPath('data.roles', ['super_admin']);
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
