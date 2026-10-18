<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

/**
 * PermissionReach — which permissions a SCOPED grant may confer (GAP-15).
 *
 * A grant is a role plus a scope (GAP-3). The scope only means something where
 * something checks it: the employee routes filter and refuse rows by it, and
 * role assignment is bounded by it. Everywhere else a permission is checked by
 * name alone — so before this, a grant scoped to ONE section that carried
 * `departments.update` could edit any unit in the company, and three such
 * permissions edit the very facts scope is computed from (who manages which
 * unit, which account is which employee, what a role carries). A scoped grant
 * could widen its own scope.
 *
 * THE RULE: a permission counts from a non-company grant only if it is listed
 * here. Everything else counts only from a company-wide grant.
 *
 * ⚠️ AN ALLOW-LIST ON PURPOSE. A permission added to the catalogue tomorrow is
 * company-only until someone lists it here — which they should do only once
 * something actually enforces its scope. The failure mode of a forgotten entry
 * is a scoped user refused a verb (loud, and fixed by adding a line); the
 * failure mode of a deny-list would be a scoped user quietly acting
 * company-wide, which is the gap this closes.
 *
 * Applied once, in `PermissionCache::resolve()`, to every grant's permission
 * list before anything reads it — so `hasPermission()`, the `permission:`
 * middleware, `/auth/me` and `AccessScope` all see the same effective set, and
 * a scoped `auth.roles.update` simply does not exist for its holder.
 *
 * Ratified 2026-09-26 (START_HERE §9, decisions 21–23).
 */
final class PermissionReach
{
    /**
     * Permissions whose scope something enforces, per row or per grant.
     *
     * - The employee family: EmployeePolicy / EmployeeWarningPolicy per row,
     *   the list filtered on the builder, the destination unit of a create or
     *   transfer checked, and the pay / identity fields gated per row.
     * - `auth.roles.assign`: RoleService::ensureMayGrant() refuses a grant
     *   beyond the assigner's own reach, and one carrying a permission they do
     *   not hold over the same units (GAP-16, decision 24). Decision 22 split
     *   it out of `auth.roles.update`, which edits role DEFINITIONS and is
     *   company-only.
     *
     * @var array<int, string>
     */
    public const ROW_SCOPED = [
        'hr.employees.view',
        'hr.employees.create',
        'hr.employees.update',
        'hr.employees.delete',
        'hr.employees.viewSalary',
        'hr.employees.viewIdentity',
        'hr.assignments.view',
        'hr.assignments.create',
        'hr.warnings.view',
        'hr.warnings.create',
        'hr.warnings.update',
        'hr.warnings.delete',
        'auth.roles.assign',
        // Attendance (2026-10-16): AttendanceRecordPolicy per row, through the
        // employee's current unit; the records list filtered on the builder.
        'attendance.records.view',
        'attendance.records.correct',
        'attendance.records.reject',
        // Approvals (2026-10-18): ApprovalPolicy per row, through the
        // requester's current unit.
        'approvals.view',
        'approvals.reassign',
        // Leave balances (2026-10-18): the list filtered on the builder, the
        // update refused by LeaveReach, both through the employee's current
        // unit.
        'leave.balances.view',
        'leave.balances.update',
    ];

    /**
     * Reads of company-wide CATALOGUES, honoured from any grant (decision 23).
     *
     * Not scoped, and not company-only either: the org chart, the job-title
     * catalogue and the list of roles hold no personal data and are company
     * configuration, and a scoped user's screens need them — the unit and title
     * pickers on the employee form, the role picker on the assign card. Their
     * WRITES stay company-only.
     *
     * @var array<int, string>
     */
    public const CATALOGUE_READS = [
        'departments.view',
        'hr.job_titles.view',
        'auth.roles.view',
    ];

    /**
     * Both lists as one set, keyed by name — built once per process.
     *
     * @var array<string, true>|null
     */
    private static ?array $honoured = null;

    /**
     * The subset of a scoped grant's permissions it actually confers, in the
     * order given.
     *
     * @param  array<int, string>  $permissions
     * @return array<int, string>
     */
    public static function effectiveForScopedGrant(array $permissions): array
    {
        self::$honoured ??= array_fill_keys([...self::ROW_SCOPED, ...self::CATALOGUE_READS], true);

        return array_values(array_filter(
            $permissions,
            static fn (string $permission): bool => isset(self::$honoured[$permission]),
        ));
    }
}
