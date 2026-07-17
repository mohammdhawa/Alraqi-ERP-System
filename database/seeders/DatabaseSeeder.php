<?php

namespace Database\Seeders;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with a realistic test dataset.
     *
     * The org chart is a single rooted tree exercising all three tiers:
     *
     *   الإدارة العامة (root, level 1)
     *     ├─ إدارة الموارد البشرية (division, level 2)
     *     │    └─ قسم التوظيف (section, level 3)
     *     ├─ الإدارة المالية (division, level 2)
     *     └─ الإدارة الهندسية (division, level 2)
     *          ├─ قسم تطوير البرمجيات (section, level 3)
     *          └─ قسم ضمان الجودة (section, level 3)
     *
     * §12 step 5: the root is seeded FIRST; every other unit hangs beneath it,
     * so the singleton-root and child-tier invariants hold at every step.
     *
     * WHY model events are LEFT ON here (no WithoutModelEvents):
     * - The hierarchy invariants now live in the Department model's saving hook,
     *   so suppressing events would let the seeder write unguarded — defeating
     *   the whole point. With events on, every seeded row passes the SAME guard
     *   the API enforces. The created/updated audit rows this produces carry
     *   user_id = null, which is exactly what §7.1 designed that column's
     *   nullability for: console actions, seeders, and jobs have no auth user.
     *
     * Beyond the tree, this seeds a spread of employees (each department pointed
     * at one of its staff as manager, exercising the deferred manager_id FK), a
     * limited "viewer" role, and a few user logins — enough to exercise
     * list/pagination endpoints and RBAC during manual and automated testing.
     */
    public function run(): void
    {
        // RBAC must come first: roles/permissions must exist before we can grant
        // them to seeded users.
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
        ]);

        $adminRole = Role::where('name', 'admin')->firstOrFail();

        // A second, limited role for testing permission enforcement: it can only
        // view, never mutate.
        $viewerRole = Role::updateOrCreate(
            ['name' => 'viewer'],
            ['description' => 'Read-only access. Holds every *.view permission.'],
        );
        $viewerRole->permissions()->sync(
            Permission::where('name', 'like', '%.view')->pluck('id'),
        );

        // --- Organizational tree: root -> divisions -> sections --------------
        // The root (الإدارة العامة) is created first so the singleton-root and
        // child-tier invariants hold as each unit below it is seeded.
        $root = $this->seedDepartment('الإدارة العامة', DepartmentLevel::GeneralAdministration, null);

        $hr          = $this->seedDepartment('إدارة الموارد البشرية', DepartmentLevel::Division, $root);
        $finance     = $this->seedDepartment('الإدارة المالية', DepartmentLevel::Division, $root);
        $engineering = $this->seedDepartment('الإدارة الهندسية', DepartmentLevel::Division, $root);

        $recruitment = $this->seedDepartment('قسم التوظيف', DepartmentLevel::Section, $hr);
        $software    = $this->seedDepartment('قسم تطوير البرمجيات', DepartmentLevel::Section, $engineering);
        $qa          = $this->seedDepartment('قسم ضمان الجودة', DepartmentLevel::Section, $engineering);

        // --- Staff, spread across all three tiers ----------------------------
        $rootEmployee = $this->seedStaff($root, [
            ['name' => 'Test User', 'job_title' => 'Administrator', 'salary' => 0],
        ]);

        $hrLead = $this->seedStaff($hr, [
            ['name' => 'Sara Ahmed', 'job_title' => 'HR Manager', 'salary' => 18000],
        ]);
        $this->seedStaff($recruitment, [
            ['name' => 'Layla Hassan', 'job_title' => 'Recruiter', 'salary' => 11000],
        ]);

        $this->seedStaff($finance, [
            ['name' => 'Omar Khalil', 'job_title' => 'Finance Manager',  'salary' => 22000],
            ['name' => 'Nour Saleh',  'job_title' => 'Accountant',       'salary' => 13000],
            ['name' => 'Yusuf Ali',   'job_title' => 'Accounts Payable', 'salary' => 9500],
        ]);

        $this->seedStaff($engineering, [
            ['name' => 'Ahmed Mansour', 'job_title' => 'Engineering Lead', 'salary' => 25000],
        ]);
        $this->seedStaff($software, [
            ['name' => 'Khaled Ibrahim', 'job_title' => 'Backend Developer',  'salary' => 17000],
            ['name' => 'Mona Farouk',    'job_title' => 'Frontend Developer', 'salary' => 16000],
        ]);
        $this->seedStaff($qa, [
            ['name' => 'Tariq Said', 'job_title' => 'QA Engineer', 'salary' => 14000],
        ]);

        // --- Users ------------------------------------------------------------
        // Accounts carry no name of their own — it comes from the linked employee
        // (users.employee_id -> employees). The admin and viewer are linked to a
        // seeded employee; the roleless account is intentionally left unlinked to
        // exercise the "account without an employee" path.
        $admin = User::query()->updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'password'    => 'password',
                'is_active'   => true,
                'employee_id' => $rootEmployee?->id,
            ],
        );
        $admin->roles()->sync($adminRole->id);

        // A read-only user for testing the viewer role / permission denials.
        $viewer = User::query()->updateOrCreate(
            ['email' => 'viewer@example.com'],
            [
                'password'    => 'password',
                'is_active'   => true,
                'employee_id' => $hrLead?->id,
            ],
        );
        $viewer->roles()->sync($viewerRole->id);

        // A user with no roles at all, for testing the "no permissions" path.
        User::query()->updateOrCreate(
            ['email' => 'noroles@example.com'],
            [
                'password'  => 'password',
                'is_active' => true,
            ],
        );
    }

    /**
     * Create (or update) a department at a given tier under a given parent, then
     * return it. Writing through the model — with events ON — means every seeded
     * row is subject to the same DepartmentHierarchyGuard the API enforces.
     */
    private function seedDepartment(string $name, DepartmentLevel $level, ?Department $parent): Department
    {
        return Department::query()->updateOrCreate(
            ['name' => $name],
            [
                'name'      => $name,
                'parent_id' => $parent?->id,
                'level'     => $level->value,
            ],
        );
    }

    /**
     * Attach staff to a department and point it at the first of them as its
     * manager (exercising the deferred manager_id FK). Returns that first
     * employee, or null when the list is empty.
     *
     * The null-check before ->update guards the exact bug the previous seeder
     * had: dereferencing $first->id for a department seeded with no staff.
     *
     * @param  array<int, array{name: string, job_title: string, salary: int|float}>  $people
     */
    private function seedStaff(Department $department, array $people): ?Employee
    {
        $first = null;

        foreach ($people as $person) {
            $email = $this->emailFor($person['name']);

            $employee = Employee::query()->updateOrCreate(
                ['email' => $email],
                [
                    'name'          => $person['name'],
                    'phone'         => $this->phoneFor($person['name']),
                    'email'         => $email,
                    'address'       => 'Riyadh, Saudi Arabia',
                    'department_id' => $department->id,
                    'job_title'     => $person['job_title'],
                    'hire_date'     => $this->hireDateFor($person['name']),
                    'salary'        => $person['salary'],
                    'status'        => 'active',
                ],
            );

            $first ??= $employee;
        }

        if ($first !== null) {
            $department->update(['manager_id' => $first->id]);
        }

        return $first;
    }

    /**
     * Build a deterministic example email from a person's name.
     * "Sara Ahmed" -> "sara.ahmed@example.com"
     */
    private function emailFor(string $name): string
    {
        $slug = strtolower(str_replace(' ', '.', trim($name)));

        return $slug . '@example.com';
    }

    /**
     * Build a deterministic Saudi-style mobile number for repeatable seeding.
     */
    private function phoneFor(string $name): string
    {
        return '+9665' . str_pad((string) (crc32($name) % 100000000), 8, '0', STR_PAD_LEFT);
    }

    /**
     * Build a deterministic hire date so repeated seeding does not churn rows.
     */
    private function hireDateFor(string $name): string
    {
        return now()->subDays(30 + (crc32($name) % 1171))->toDateString();
    }
}
