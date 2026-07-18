<?php

namespace Tests;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed the RBAC permission/role catalogue. Idempotent, so it is safe to
     * call from multiple helpers within a single test.
     */
    protected function seedRbac(): void
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    }

    /**
     * Create a user holding the seeded "super_admin" role, authenticate them via
     * Sanctum, and return them. A super admin bypasses every permission check
     * (Gate::before + hasPermission short-circuit), so this is the go-to actor
     * for endpoints guarded by the `permission:` middleware.
     */
    protected function actingAsAdmin(): User
    {
        $this->seedRbac();

        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', User::SUPER_ADMIN_ROLE)->value('id'));

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Create an authenticated user with NO roles/permissions, for asserting
     * that permission-guarded endpoints return 403.
     */
    protected function actingAsRolelessUser(): User
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Create an authenticated user whose single role carries exactly the given
     * permission names, then authenticate them via Sanctum.
     *
     * Use this to assert that a user holding only some permissions (e.g. a
     * read-only "*.view") is still forbidden from actions guarded by other
     * permissions (create/update/delete).
     *
     * @param  array<int, string>  $permissions  permission names from the catalogue
     */
    protected function actingAsUserWithPermissions(array $permissions): User
    {
        $this->seedRbac();

        $role = Role::create(['name' => 'test-role-' . uniqid()]);
        $role->permissions()->sync(
            Permission::whereIn('name', $permissions)->pluck('id'),
        );

        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        Sanctum::actingAs($user);

        return $user;
    }
}
