<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Support\PermissionCache;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Seeder;

/**
 * Development fixtures — invented units, invented staff, and login accounts.
 *
 * WHY THIS FILE EXISTS: everything in it used to live in DatabaseSeeder, which
 * meant `migrate:fresh --seed` produced a company containing قسم تطوير البرمجيات
 * and قسم ضمان الجودة. Al-Raqi is a construction company; it has neither. Mixing
 * fabricated units into the same seeder as the real tree makes "is this row
 * real?" unanswerable without reading the source spreadsheet — and the answer
 * matters, because these rows are about to become the target of an employee
 * import keyed on `code`.
 *
 * So the split is by TRUTH, not by convenience: DepartmentSeeder holds what the
 * company actually is, this holds what developers need to click around. It is
 * skipped in production (see DatabaseSeeder).
 *
 * It REQUIRES DepartmentSeeder to have run. The invented sections hang off real
 * divisions rather than off a root of their own — the singleton-root invariant
 * has no development exemption, and a second parentless row is refused by the
 * guard on the spot.
 *
 * قسم التوظيف is deliberately NOT carried over from the old seeder: the real
 * tree has قسم الاستقطاب والتوظيف (HR-REC), which is the same unit under its
 * real name. The recruiter below is attached to that.
 */
class DevDepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $marketing = Department::query()->where('code', 'MKT')->firstOrFail();
        $recruitment = Department::query()->where('code', 'HR-REC')->firstOrFail();
        $hr = Department::query()->where('code', 'HR')->firstOrFail();
        $finance = Department::query()->where('code', 'FIN')->firstOrFail();
        $engineering = Department::query()->where('code', 'ENG')->firstOrFail();
        $root = Department::query()->whereNull('parent_id')->firstOrFail();

        // Invented sections, under a real division so the tree stays one tree.
        // They keep the DEV- prefix so a glance at `code` tells you the row is
        // not from the org chart.
        $software = $this->seedUnit('DEV-SW', 'قسم تطوير البرمجيات', $marketing);
        $qa = $this->seedUnit('DEV-QA', 'قسم ضمان الجودة', $marketing);

        // --- Staff, spread across the tiers ----------------------------------
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

        $this->seedAccounts($rootEmployee, $hrLead);
    }

    /**
     * Create (or update) an invented section under a real division.
     *
     * Level is taken from the parent rather than stated, for the same reason
     * DepartmentSeeder derives it: a literal here could disagree with where the
     * row actually sits.
     */
    private function seedUnit(string $code, string $name, Department $parent): Department
    {
        return Department::query()->updateOrCreate(
            ['code' => $code],
            [
                'name'      => $name,
                'parent_id' => $parent->id,
                'level'     => $parent->level->next()?->value,
            ],
        );
    }

    /**
     * A limited role and three accounts: a super admin, a read-only viewer, and
     * one with no roles at all to exercise the "no permissions" path. The last
     * is deliberately left unlinked to any employee, which is the other case
     * worth having — an account with no HR record behind it.
     */
    private function seedAccounts(?Employee $rootEmployee, ?Employee $hrLead): void
    {
        $superAdminRole = Role::where('name', User::SUPER_ADMIN_ROLE)->firstOrFail();

        $viewerRole = Role::updateOrCreate(
            ['name' => 'viewer'],
            [
                'label'       => 'مطالع',
                'description' => 'Read-only access. Holds every *.view permission.',
            ],
        );
        $viewerRole->permissions()->sync(
            Permission::where('name', 'like', '%.view')->pluck('id'),
        );

        // Accounts carry no name of their own — it comes from the linked
        // employee (users.employee_id -> employees).
        $admin = User::query()->updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'password'    => 'password',
                'is_active'   => true,
                'employee_id' => $rootEmployee?->id,
            ],
        );
        $admin->roles()->sync($superAdminRole->id);

        $viewer = User::query()->updateOrCreate(
            ['email' => 'viewer@example.com'],
            [
                'password'    => 'password',
                'is_active'   => true,
                'employee_id' => $hrLead?->id,
            ],
        );
        $viewer->roles()->sync($viewerRole->id);

        User::query()->updateOrCreate(
            ['email' => 'noroles@example.com'],
            [
                'password'  => 'password',
                'is_active' => true,
            ],
        );

        // Roles/permissions were attached directly via the pivots above (not
        // through RoleService), so invalidate any cached permission sets.
        PermissionCache::flush();
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
