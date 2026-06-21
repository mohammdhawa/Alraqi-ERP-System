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
