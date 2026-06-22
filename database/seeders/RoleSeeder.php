<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use Illuminate\Database\Seeder;

/**
 * RoleSeeder
 *
 * Creates the baseline "admin" role and grants it every registered permission.
 * Must run AFTER PermissionSeeder so the permissions exist to attach.
 *
 * Idempotent: sync() makes the admin role's permission set exactly the current
 * catalogue on every run, so newly added permissions are picked up.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $admin = Role::updateOrCreate(
            ['name' => 'admin'],
            ['description' => 'Full system access. Holds every permission.'],
        );

        // Grant all permissions to admin. sync() keeps this exact and avoids
        // duplicate pivot rows on re-seed.
        $admin->permissions()->sync(Permission::query()->pluck('id'));
    }
}
