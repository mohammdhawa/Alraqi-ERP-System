<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Support\PermissionCache;
use Illuminate\Database\Seeder;

/**
 * RoleSeeder
 *
 * Seeds the one built-in role the RBAC system depends on: super_admin.
 *
 * WHY super_admin holds NO permissions:
 * - The architecture prohibits granting every permission to a single role (the
 *   old seeder did exactly that). A super admin's power is the Gate::before
 *   bypass (AppServiceProvider) + the hasPermission() short-circuit, keyed on
 *   this role's name. So a NEW permission is instantly available to super admins
 *   with no re-seed, and there is no giant permission set to keep in sync.
 * - is_system marks it immutable: RoleService refuses to rename or delete it.
 *
 * Ordinary roles (e.g. the seeded "viewer" in DatabaseSeeder) are created
 * elsewhere with explicit permission sets. This runs before those and does not
 * depend on PermissionSeeder (it attaches no permissions).
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::updateOrCreate(
            ['name' => User::SUPER_ADMIN_ROLE],
            [
                'label'       => 'مدير النظام',
                'description' => 'Full system access via the Gate::before bypass. Holds no explicit permissions.',
                'is_system'   => true,
            ],
        );

        // Roles changed: invalidate any cached permission sets.
        PermissionCache::flush();
    }
}
