<?php

namespace Tests;

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
     * Create a user holding the seeded "admin" role (every permission),
     * authenticate them via Sanctum, and return them.
     *
     * Use this for endpoints guarded by the `permission:` middleware now that
     * RBAC is enforced — a role-less user would be rejected with 403.
     */
    protected function actingAsAdmin(): User
    {
        $this->seedRbac();

        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'admin')->value('id'));

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
}
