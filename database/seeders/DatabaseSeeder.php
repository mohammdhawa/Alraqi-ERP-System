<?php

namespace Database\Seeders;

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
     * relationship: a Department, an Employee in it, and a User linked to that
     * employee. The department is then pointed back at the employee as its
     * manager, exercising the deferred manager_id FK.
     */
    public function run(): void
    {
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

        User::factory()->create([
            'name'        => 'Test User',
            'email'       => 'test@example.com',
            'employee_id' => $employee->id,
        ]);

        // Close the loop: the employee manages the department they belong to.
        $department->update(['manager_id' => $employee->id]);
    }
}
