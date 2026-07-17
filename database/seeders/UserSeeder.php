<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Ensures roles/permissions exist first, then grants the admin role
     * (which holds every permission via RoleSeeder) to this user.
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

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $user->roles()->sync($adminRole->id);
    }
}
