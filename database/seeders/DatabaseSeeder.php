<?php

namespace Database\Seeders;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Builds the full identity chain end to end so login works against the new
     * relationship: roles/permissions, a Department, an Employee in it, and a
     * User linked to that employee and granted the admin role. The department
     * is then pointed back at the employee as its manager, exercising the
     * deferred manager_id FK.
     */
    public function run(): void
    {
        // RBAC must come first: the admin role/permissions must exist before
        // we can grant them to the seeded user.
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
        ]);

        $department = Department::create([
            'name' => 'Management',
        ]);

        $employee = Employee::create([
            'name'          => 'Test User',
            'email'         => 'test@example.com',
            'department_id' => $department->id,
            'job_title'     => 'Administrator',
            'hire_date'     => now()->toDateString(),
            'salary'        => 0,
            'status'        => 'active',
        ]);

        $user = User::factory()->create([
            'name'        => 'Test User',
            'email'       => 'test@example.com',
            'employee_id' => $employee->id,
        ]);

        // Grant the seeded user full access so the system has a working admin
        // out of the box (and so CheckPermission lets them through).
        $user->roles()->sync(Role::where('name', 'admin')->value('id'));

        // Close the loop: the employee manages the department they belong to.
        $department->update(['manager_id' => $employee->id]);
    }
}
