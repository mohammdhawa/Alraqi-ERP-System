<?php

declare(strict_types=1);

namespace App\Modules\Auth\Resources;

use App\Modules\Auth\Models\User;
use Illuminate\Http\Request;

/**
 * AuditLogDetailResource
 *
 * One audit row IN FULL: everything the list item carries, plus the before/after
 * values and the user agent.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * THE VALUES BLOCK IS A SEPARATE PERMISSION, and this is why
 * ──────────────────────────────────────────────────────────────────────────
 * `AuditLogService::sanitize()` redacts credentials plus `national_id`,
 * `salary`, `agreed_salary` and `sham_cash_account` before storage. It does NOT
 * redact the nine classification axes — `activity_type`,
 * `organizational_grade`, `experience_level`, `engineering_rank`,
 * `industrial_experience_years`, the three pay flags and `distinction_grade` —
 * which `hr.employees.viewSalary` gates everywhere else precisely because they
 * are the inputs pay is computed from. So an employee update writes them
 * verbatim, and without a second gate the audit trail would be a way around a
 * permission the rest of the system enforces (C11).
 *
 * `auth.audit.viewValues` is that gate. It is field-level, like
 * viewSalary/viewIdentity: no route carries it, and holding `auth.audit.view`
 * alone gets you the whole trail — who, what, when, from where, and which
 * fields moved — without the values themselves.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * AND IT IS SCOPED TO THE TARGET'S OWN MODULE, which is the second half
 * ──────────────────────────────────────────────────────────────────────────
 * viewValues on its own was not a read-around for `hr.employees.viewSalary`;
 * it was a read-around for EVERY read permission in the system. `HasAuditLog`
 * fills `new_values` from `$model->getAttributes()`, so a caller holding
 * `auth.audit.view` + `viewValues` and nothing else could page
 * `?event=created,updated,deleted&auditable_type=employee` and reconstruct the
 * whole of employees, departments, job_titles, users and roles — minus only the
 * eleven strings `AuditLogService::sanitize()` redacts — without
 * `hr.employees.view` or `auth.users.view` ever being granted. Neither option
 * GAP-13 recorded (redact the axes, or imply viewSalary) touches that, because
 * GAP-13 was written about the axes and this is about every column there is.
 *
 * So the values also require THE VIEW PERMISSION OF THE MODULE THE ROW IS
 * ABOUT. An audit row is a copy of someone else's record; the permission to
 * read audit rows is not the permission to read that record. Rows with no
 * target at all (login, `api_request`) need only viewValues — their blobs hold
 * request and auth metadata, which is nobody else's data.
 *
 * WHAT REMAINS is exactly what GAP-13 always described and no more: a caller
 * holding `hr.employees.view` + viewValues but not `hr.employees.viewSalary`
 * can still read the nine axes out of an employee's history. Redacting them at
 * write time would make "who changed this person's grade, and to what"
 * unanswerable forever, on a table that cannot be scrubbed OR un-scrubbed after
 * the fact, so that one stays a policy decision rather than a code change.
 *
 * ABSENT ≠ NULL, and both occur here (C11, C31):
 *   key missing  → you may not read this
 *   key null     → there is nothing to read: `created` has no old values,
 *                  `deleted` has no new ones
 * A client that reads a missing key as "no changes" will report an edit as an
 * empty diff, which is why the API doc states this rule in §1.
 *
 * `***REDACTED***` inside the blob is a third thing again: the field existed,
 * and the real value was never written to this table at all.
 */
class AuditLogDetailResource extends AuditLogResource
{
    /**
     * Permission gating the before/after values.
     */
    public const VIEW_VALUES = 'auth.audit.viewValues';

    /**
     * Morph alias => the permission that grants reading THAT KIND OF RECORD
     * anywhere else in the system. Held in addition to VIEW_VALUES before an
     * audit row's blobs are served.
     *
     * The values are the same strings the modules' own read routes carry, read
     * off `HR/routes.php`, `Departments/routes.php` and `Auth/routes.php` —
     * this table decides nothing, it only points at the existing gate.
     * `employee_document` takes `hr.employees.view` because documents have no
     * route or permission of their own yet and live inside the employee
     * profile; `permission` takes `auth.roles.view` for the same reason.
     *
     * ⚠️ AN ALIAS MISSING FROM THIS MAP FAILS CLOSED — no values, for anyone
     * short of a super admin. That is deliberate and it is why there is no
     * default: "we have not decided who may read this record" must not resolve
     * to "everybody with viewValues". AuditTargetStubTest fails the build when
     * a morph alias is missing here, so the closed state is a build error and
     * not something a user discovers.
     *
     * @var array<string, string>
     */
    public const TYPE_VIEW_PERMISSIONS = [
        'employee'            => 'hr.employees.view',
        'employee_assignment' => 'hr.assignments.view',
        'employee_document'   => 'hr.employees.view',
        'employee_warning'    => 'hr.warnings.view',
        'job_title'           => 'hr.job_titles.view',
        'department'          => 'departments.view',
        'user'                => 'auth.users.view',
        'role'                => 'auth.roles.view',
        'permission'          => 'auth.roles.view',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            ...$this->valuesBlock($request),

            // Ungated: a user agent is transport metadata, not a fact about a
            // person. Clients truncate it — a raw UA string is long enough to
            // wreck a layout.
            'user_agent' => $this->user_agent,
        ];
    }

    /**
     * The two values keys, or an empty array for a caller without the
     * permission.
     *
     * Built as one block rather than two `when()` calls so the pair can never
     * half-appear: one key present and the other absent would say "you may read
     * the new values but not the old", which is not a permission that exists.
     *
     * @return array<string, mixed>
     */
    private function valuesBlock(Request $request): array
    {
        $user = $request->user();

        // FAILS CLOSED, matching EmployeeResource: no authenticated user — a
        // console render, a queued job — means no values. A super admin passes
        // through hasPermission()'s own bypass.
        if ($user === null || ! $user->hasPermission(self::VIEW_VALUES)) {
            return [];
        }

        if (! $this->mayReadTarget($user)) {
            return [];
        }

        return [
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
        ];
    }

    /**
     * Whether the caller may read the KIND OF RECORD this row is about.
     *
     * The RAW column decides whether there is a target at all, not the accessor:
     * `auditable_type` reads as null both for an action-level row (no target
     * ever existed) and for a stored type that no longer resolves to a class
     * (a target exists and we cannot name it). Those are opposite answers here —
     * the first has no module data in its blobs, the second has a full copy of
     * some module's row and merely lost the label. Reading the accessor would
     * hand the second one out under the first one's reasoning.
     *
     * An alias with no entry in the map is refused, including one that is in the
     * morph map but not yet here; see the constant.
     */
    private function mayReadTarget(User $user): bool
    {
        if ($this->resource->getRawOriginal('auditable_type') === null) {
            return true;
        }

        $type       = $this->auditable_type;
        $permission = $type === null ? null : (self::TYPE_VIEW_PERMISSIONS[$type] ?? null);

        return $permission !== null && $user->hasPermission($permission);
    }
}
