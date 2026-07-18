<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Support\PermissionCache;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Ensures roles/permissions exist first, then grants the super_admin role
     * (which bypasses every permission check) to this bootstrap account.
     */
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
        ]);

        // The account carries no name of its own — a display name, if needed,
        // comes from a linked employee (users.employee_id). This bootstrap admin
        // is intentionally left unlinked.
        $user = User::query()->updateOrCreate(
            [
                'email' => 'ragab5434@gmail.com',
            ],
            [
                'password'  => 'MoH.1822', // auto-hashed via cast
                'is_active' => true,
            ]
        );

        $superAdminRole = Role::where('name', User::SUPER_ADMIN_ROLE)->firstOrFail();
        $user->roles()->sync($superAdminRole->id);

        // Direct pivot write: invalidate any cached permission sets.
        PermissionCache::flush();
    }
}
