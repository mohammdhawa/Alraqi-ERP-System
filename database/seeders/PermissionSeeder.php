<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Auth\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * PermissionSeeder
 *
 * Registers the Phase 1 permission catalogue. Each `name` is exactly the
 * string a route passes to the `permission:` middleware, so these rows are
 * what make CheckPermission able to authorize requests.
 *
 * NAMING NOTE: Departments became its own module (prefix /api/departments), so
 * its permissions are `departments.*` — matching that module's route
 * middleware — rather than the `hr.departments.*` the original spec assumed
 * while departments still lived under HR. Employees remain `hr.employees.*`.
 *
 * Idempotent: re-running updates module/description without duplicating rows.
 */
class PermissionSeeder extends Seeder
{
    /**
     * @var array<string, array<int, string>> module => actions
     */
    private const RESOURCES = [
        // Departments module
        'departments' => ['departments.view', 'departments.create', 'departments.update', 'departments.delete'],
        // HR module.
        //
        // viewSalary and viewIdentity are FIELD-LEVEL permissions, not route
        // permissions: no route carries them. They are checked inside
        // EmployeeResource to decide whether `salary` / `national_id` appear in
        // the payload (C11). They are real permissions all the same — seeded,
        // assignable, and shown in the admin UI like any other.
        //
        // hr.assignments has NO update or delete, and that is a design
        // statement rather than an omission: سجل التعيين is append-only. A
        // posting recorded wrongly is corrected by recording the correction,
        // the way a ledger is. Letting someone edit last year's posting would
        // let them change what a payslip was computed against, after the fact
        // and without a trace of the original.
        'hr'   => [
            'hr.employees.view', 'hr.employees.create', 'hr.employees.update', 'hr.employees.delete',
            'hr.employees.viewSalary', 'hr.employees.viewIdentity',
            'hr.job_titles.view', 'hr.job_titles.create', 'hr.job_titles.update', 'hr.job_titles.delete',
            'hr.assignments.view', 'hr.assignments.create',
            'hr.warnings.view', 'hr.warnings.create', 'hr.warnings.update', 'hr.warnings.delete',
        ],
        // Auth module.
        //
        // auth.audit has NO create, update or delete, and — like hr.assignments
        // above — that is a design statement rather than an omission, only more
        // absolutely: audit_logs is append-only, and a permission to edit or
        // erase an audit row is the one power an audit trail exists to deny.
        // There is nothing to grant, so there is nothing seeded.
        //
        // auth.audit.viewValues is FIELD-LEVEL, like hr.employees.viewSalary:
        // no route carries it. AuditLogDetailResource checks it to decide
        // whether `old_values` / `new_values` appear in the payload (C11). It is
        // separate from .view because the trail records the classification axes
        // verbatim — AuditLogService::sanitize() redacts the salary itself but
        // not its inputs — so one permission would make the audit log a way
        // around hr.employees.viewSalary.
        'auth' => [
            'auth.users.view', 'auth.users.create', 'auth.users.update', 'auth.users.delete',
            'auth.roles.view', 'auth.roles.create', 'auth.roles.update', 'auth.roles.delete',
            // Granting a role to a user — split out of auth.roles.update on
            // 2026-09-26 (GAP-15). Assigning is bounded by the assigner's own
            // reach; editing what a role carries is company-wide by nature.
            // One permission could not be both. (The reach bound is on where,
            // not what — GAP-16 — so do not grant this over a unit yet.)
            'auth.roles.assign',
            'auth.audit.view', 'auth.audit.viewValues',
        ],
        // Attendance module (work order §4.5; mapping §9).
        //
        // Recording one's own attendance needs NO permission — every active
        // employee with an account records (Q7) through the self-scoped
        // /api/me/attendance. These are the oversight permissions.
        //
        // All three are ROW-SCOPED through the employee's CURRENT unit (D6):
        // AttendanceRecordPolicy per row, the list filtered on the builder
        // (slice 2). Correcting and refusing are separate grants, as legacy's
        // were — a manager may see their units' attendance without being able
        // to refuse it.
        'attendance' => [
            'attendance.records.view',
            'attendance.records.correct',
            'attendance.records.reject',
        ],
        // Approvals engine (work order §4.6; APPROVALS_ENGINE_DESIGN.md §7).
        //
        // APPROVING IS NOT A PERMISSION: you act on a step because you are its
        // approver. `approvals.view` is reading a chain you are not on (§9 Q5);
        // `approvals.reassign` is handing a pending step to someone else (§9
        // Q4). Both row-scoped through the requester's current unit
        // (ApprovalPolicy).
        'approvals' => [
            'approvals.view',
            'approvals.reassign',
        ],
        // Leave (work order §4.7; LEAVE_BUILD.md §7). Filing, reading and
        // withdrawing your own leave need none — /api/me. The type catalogue's
        // writes are company-only grants, as departments and job titles.
        // The two reads are seeded with their tables because the audit trail
        // must gate the new types, and are company-only until their endpoints
        // enforce a row scope — as `approvals.view` was.
        'leave' => [
            'leave.types.create',
            'leave.types.update',
            'leave.types.delete',
            'leave.balances.view',
            'leave.balances.update',
            'leave.requests.view',
        ],
    ];

    /**
     * Arabic display labels for each permission — the human-facing name an admin
     * UI shows (the `name` stays the machine identifier the middleware matches).
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'departments.view'          => 'عرض الوحدات التنظيمية',
        'departments.create'        => 'إنشاء وحدة تنظيمية',
        'departments.update'        => 'تعديل وحدة تنظيمية',
        'departments.delete'        => 'حذف وحدة تنظيمية',
        'hr.employees.view'         => 'عرض الموظفين',
        'hr.employees.create'       => 'إضافة موظف',
        'hr.employees.update'       => 'تعديل موظف',
        'hr.employees.delete'       => 'حذف موظف',
        'hr.employees.viewSalary'   => 'عرض رواتب الموظفين',
        'hr.employees.viewIdentity' => 'عرض أرقام هوية الموظفين',
        'hr.job_titles.view'        => 'عرض المسميات الوظيفية',
        'hr.job_titles.create'      => 'إنشاء مسمى وظيفي',
        'hr.job_titles.update'      => 'تعديل مسمى وظيفي',
        'hr.job_titles.delete'      => 'حذف مسمى وظيفي',
        'hr.assignments.view'       => 'عرض سجل التعيين',
        'hr.assignments.create'     => 'تسجيل تعيين أو نقل',
        'hr.warnings.view'          => 'عرض الإنذارات',
        'hr.warnings.create'        => 'إصدار إنذار',
        'hr.warnings.update'        => 'تعديل إنذار',
        'hr.warnings.delete'        => 'حذف إنذار',
        'auth.users.view'           => 'عرض المستخدمين',
        'auth.users.create'         => 'إنشاء مستخدم',
        'auth.users.update'         => 'تعديل مستخدم',
        'auth.users.delete'         => 'حذف مستخدم',
        'auth.roles.view'           => 'عرض الأدوار',
        'auth.roles.create'         => 'إنشاء دور',
        'auth.roles.update'         => 'تعديل دور',
        'auth.roles.delete'         => 'حذف دور',
        'auth.roles.assign'         => 'إسناد الأدوار للمستخدمين',
        'auth.audit.view'           => 'عرض سجل التدقيق',
        'auth.audit.viewValues'     => 'عرض تفاصيل التغييرات',
        'attendance.records.view'    => 'عرض سجلات الحضور',
        'attendance.records.correct' => 'تصحيح انصراف منسي',
        'attendance.records.reject'  => 'رفض سجل حضور',
        'approvals.view'             => 'عرض مسارات الاعتماد',
        'approvals.reassign'         => 'إعادة إسناد خطوة اعتماد',
        'leave.types.create'         => 'إضافة نوع إجازة',
        'leave.types.update'         => 'تعديل نوع إجازة',
        'leave.types.delete'         => 'حذف نوع إجازة',
        'leave.balances.view'        => 'عرض أرصدة الإجازات',
        'leave.balances.update'      => 'تعديل أرصدة الإجازات',
        'leave.requests.view'        => 'عرض طلبات الإجازات',
    ];

    public function run(): void
    {
        foreach (self::RESOURCES as $module => $permissions) {
            foreach ($permissions as $name) {
                Permission::updateOrCreate(
                    ['name' => $name],
                    [
                        'label'       => self::LABELS[$name] ?? $name,
                        'module'      => $module,
                        'description' => $this->describe($name),
                    ],
                );
            }
        }

        // NO flush() HERE, AND THAT IS NOT AN OMISSION. Seeding leaves every
        // cached permission set stale, and it is Permission::booted() that
        // flushes — on the model, so the same invariant holds for the admin UI,
        // a data migration and tinker, none of which would have thought to call
        // this seeder's copy of it. PermissionSeederTest still asserts the
        // version moves when this runs.
    }

    /**
     * Build a human-readable description from a permission name.
     * "hr.employees.view" -> "View hr employees"
     */
    private function describe(string $name): string
    {
        $parts  = explode('.', $name);
        $action = array_pop($parts);

        return ucfirst($action).' '.implode(' ', $parts);
    }
}
