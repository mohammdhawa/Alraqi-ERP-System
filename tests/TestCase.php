<?php

namespace Tests;

use App\Modules\Auth\Enums\ScopeType;
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
    private static int $koboNumber = 0;

    /**
     * A fresh Kobo id for an employee a test creates through the API: the
     * number is required and unique (KOBO_EMPLOYEE_NUMBER_DESIGN.md §3). 6 +
     * eight digits keeps these clear of the factory's 7… and HR's 5….
     */
    protected function nextKoboNumber(): string
    {
        return '6'.str_pad((string) ++self::$koboNumber, 8, '0', STR_PAD_LEFT);
    }

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

        $role = Role::create(['name' => 'test-role-'.uniqid()]);
        $role->permissions()->sync(
            Permission::whereIn('name', $permissions)->pluck('id'),
        );

        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Like actingAsUserWithPermissions(), but the one grant carries a SCOPE
     * (GAP-3) — the actor every row-level test needs. `actingAsAdmin()` is
     * useless for those: a super admin bypasses scope, so every assertion would
     * pass for the wrong reason.
     *
     * Pass `$employeeId` to link the account to an employee, which is what a
     * `Managed` grant resolves through.
     *
     * @param  array<int, string>  $permissions
     */
    protected function actingAsScopedUser(
        array $permissions,
        ScopeType $scope,
        ?int $departmentId = null,
        ?int $employeeId = null,
    ): User {
        $this->seedRbac();

        $role = Role::create(['name' => 'scoped-role-'.uniqid()]);
        $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));

        $user = User::factory()->create(['employee_id' => $employeeId]);
        $user->roles()->attach($role->id, [
            'scope_type'          => $scope->value,
            'scope_department_id' => $departmentId,
        ]);

        Sanctum::actingAs($user);

        return $user;
    }
}
