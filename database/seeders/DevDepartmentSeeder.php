<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Support\PermissionCache;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Enums\ActivityType;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Enums\EngineeringRank;
use App\Modules\HR\Enums\Gender;
use App\Modules\HR\Enums\SalaryMode;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\JobTitle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

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
    /**
     * The day fixture hire dates count back from. See hireDateFor().
     */
    private const HIRE_DATE_ANCHOR = '2026-09-01';

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
        //
        // Names are three-part now (الاسم الثلاثي) because `employees.name` no
        // longer exists, and each person carries a real classification: the
        // fixtures have to exercise the axes, or a developer clicking around
        // never sees the pay-gated block at all. There is no `salary` — for
        // نظام رواتب staff it is computed, not stored.
        $rootEmployee = $this->seedStaff($root, [
            ['first' => 'Test', 'middle' => 'M', 'last' => 'User', 'title' => 'مدير عام', 'activity' => ActivityType::Administrative, 'grade' => 1],
        ]);

        $hrLead = $this->seedStaff($hr, [
            ['first' => 'Sara', 'middle' => 'Ahmed', 'last' => 'Khoury', 'title' => 'مدير الموارد البشرية', 'activity' => ActivityType::Administrative, 'grade' => 2],
        ]);
        $this->seedStaff($recruitment, [
            ['first' => 'Layla', 'middle' => 'Hassan', 'last' => 'Nasser', 'title' => 'أخصائي استقطاب', 'activity' => ActivityType::Administrative, 'grade' => 4],
        ]);

        $this->seedStaff($finance, [
            ['first' => 'Omar',  'middle' => 'Khalil', 'last' => 'Haddad', 'title' => 'مدير مالي', 'activity' => ActivityType::Financial, 'grade' => 2],
            ['first' => 'Nour',  'middle' => 'Saleh',  'last' => 'Aziz',   'title' => 'محاسب',     'activity' => ActivityType::Financial, 'grade' => 4],
            ['first' => 'Yusuf', 'middle' => 'Ali',    'last' => 'Deeb',   'title' => 'أمين صندوق', 'activity' => ActivityType::Financial, 'grade' => 5],
        ]);

        // The engineering staff are what make the engineering-rank axis
        // reachable in development — it is refused on every other activity type.
        $this->seedStaff($engineering, [
            ['first' => 'Ahmed', 'middle' => 'Mansour', 'last' => 'Rifai', 'title' => 'رئيس القسم الهندسي', 'activity' => ActivityType::Engineering, 'grade' => 2, 'rank' => EngineeringRank::Consultant],
        ]);
        $this->seedStaff($software, [
            ['first' => 'Khaled', 'middle' => 'Ibrahim', 'last' => 'Omar',  'title' => 'مطور برمجيات', 'activity' => ActivityType::Engineering, 'grade' => 4, 'rank' => EngineeringRank::Practitioner],
            ['first' => 'Mona',   'middle' => 'Farouk',  'last' => 'Sabbagh', 'title' => 'مصمم واجهات', 'activity' => ActivityType::Engineering, 'grade' => 4, 'rank' => EngineeringRank::Associate],
        ]);
        $this->seedStaff($qa, [
            ['first' => 'Tariq', 'middle' => 'Said', 'last' => 'Jaber', 'title' => 'مهندس جودة', 'activity' => ActivityType::Engineering, 'grade' => 4, 'rank' => EngineeringRank::AssistantEngineer],
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
     * @param  array<int, array{first: string, middle: string, last: string, title: string, activity: ActivityType, grade: int, rank?: EngineeringRank}>  $people
     */
    private function seedStaff(Department $department, array $people): ?Employee
    {
        $first = null;

        foreach ($people as $person) {
            $displayName = implode(' ', [$person['first'], $person['middle'], $person['last']]);
            $email       = $this->emailFor($displayName);

            $employee = Employee::query()->firstOrNew(['email' => $email]);

            // Every employee has a Kobo id as their number (KOBO_EMPLOYEE_NUMBER
            // _DESIGN.md K1), and it never changes (K2): an invented one, set
            // only when the person is created — a re-seed leaves it, as it
            // leaves an EMP-… number seeded before this rule. 9 + eight digits
            // from the email: stable across runs, clear of HR's 5xxxxxxxx ids.
            if (! $employee->exists) {
                $employee->employee_number = '9'.str_pad((string) (crc32($email) % 100000000), 8, '0', STR_PAD_LEFT);
            }

            $employee->fill(
                [
                    'first_name'    => $person['first'],
                    'middle_name'   => $person['middle'],
                    'last_name'     => $person['last'],
                    'gender'        => Gender::Male,
                    'phone'         => $this->phoneFor($displayName),
                    'email'         => $email,
                    'address'       => 'حلب، سوريا',
                    'department_id' => $department->id,
                    'job_title_id'  => $this->seedJobTitle($person)->id,
                    'hire_date'     => $this->hireDateFor($displayName),

                    // The pay-gated axes, so the viewSalary gate has something
                    // to hide in development. `rank` is only ever present on
                    // engineering people — the guard refuses it on the rest.
                    'activity_type'        => $person['activity'],
                    'organizational_grade' => $person['grade'],
                    'engineering_rank'     => $person['rank'] ?? null,
                    'salary_mode'          => SalaryMode::Scale,
                    'status'               => EmployeeStatus::Active,
                ],
            )->save();

            $first ??= $employee;
        }

        if ($first !== null) {
            $department->update(['manager_id' => $first->id]);
        }

        return $first;
    }

    /**
     * Find or create the catalogue entry for a fixture person's title.
     *
     * Keyed on `name` because the catalogue is company-wide and unique on it —
     * so two accountants in two divisions resolve to ONE row, which is the whole
     * point of the ratified shared catalogue and is worth having the fixtures
     * demonstrate.
     *
     * @param  array{title: string, activity: ActivityType, grade: int}  $person
     */
    private function seedJobTitle(array $person): JobTitle
    {
        return JobTitle::query()->updateOrCreate(
            ['name' => $person['title']],
            [
                'default_activity_type'        => $person['activity'],
                'default_organizational_grade' => $person['grade'],
                'is_described'                 => false,
                'is_active'                    => true,
            ],
        );
    }

    /**
     * Build a deterministic example email from a person's name.
     * "Sara Ahmed Khoury" -> "sara.ahmed.khoury@example.com"
     */
    private function emailFor(string $name): string
    {
        $slug = strtolower(str_replace(' ', '.', trim($name)));

        return $slug.'@example.com';
    }

    /**
     * Build a deterministic Saudi-style mobile number for repeatable seeding.
     */
    private function phoneFor(string $name): string
    {
        return '+9665'.str_pad((string) (crc32($name) % 100000000), 8, '0', STR_PAD_LEFT);
    }

    /**
     * Build a deterministic hire date so repeated seeding does not churn rows.
     *
     * ⚙️ From a FIXED anchor since 2026-09-28. It used to count back from
     * `now()`, so the docblock's promise held only on the day it was written:
     * every later re-seed moved every hire date, which is how GAP-19 (the
     * opening posting not following a hire-date correction) showed up in the
     * dev database. Anchored before any fixture's plausible first run, so a
     * seeded date is never in the future.
     */
    private function hireDateFor(string $name): string
    {
        return Carbon::parse(self::HIRE_DATE_ANCHOR)->subDays(30 + (crc32($name) % 1171))->toDateString();
    }
}
