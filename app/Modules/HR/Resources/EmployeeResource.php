<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * EmployeeResource
 *
 * API contract for an employee, decoupled from the Eloquent model (§16.8).
 *
 * Response shape for a requester holding BOTH field-level permissions:
 * {
 *   "id": 1,
 *   "employee_number": "EMP-00001",
 *   "name": "Jane Doe",
 *   "national_id": "1098765432",     // only with hr.employees.viewIdentity
 *   "phone": "+1...",
 *   "email": "jane@example.com",
 *   "address": "...",
 *   "department_id": 2,
 *   "job_title": "Engineer",
 *   "hire_date": "2026-01-15",
 *   "salary": "85000.00",            // only with hr.employees.viewSalary
 *   "status": "active",
 *   "created_at": "...",
 *   "updated_at": "..."
 * }
 *
 * FIELD-LEVEL GATING (C11) — the two keys above are OMITTED ENTIRELY, not
 * nulled, for a requester without the matching permission. An absent key and a
 * null value mean different things: null would assert "this employee has no
 * national id", which is a lie about the data rather than a statement about the
 * reader's access.
 *
 * WHY THIS WAS ADDED: both fields shipped ungated to anyone holding
 * hr.employees.view — the permission a receptionist or a scheduler would
 * reasonably be granted. C11 names salary AND national id as sensitive, and
 * "gated by omission-by-habit" was exactly what was happening: the fields were
 * simply listed, and nothing had ever decided who should see them.
 *
 * WHY TWO PERMISSIONS AND NOT ONE: pay and identity are different needs held by
 * different people. Payroll needs the salary and has no business with national
 * ids; HR administration needs the national id to file paperwork and may have no
 * business with pay. Folding both behind a single `viewSalary` would force
 * whoever assigns roles to over-grant one to get the other, and would make the
 * permission's name a lie about half of what it unlocks.
 *
 * Batch 2 extends the viewSalary gate to `agreed_salary`, `sham_cash_account`
 * and the classification axes (activity_type, organizational_grade,
 * experience_level, engineering_rank …). Those axes are gated for a reason
 * worth restating: they are the INPUTS the salary is computed from, so exposing
 * them while hiding the result would be theatre.
 *
 * NOTE: this governs READ exposure only. Who may WRITE these fields is still
 * hr.employees.create / .update — anyone who can edit an employee can set a
 * salary they cannot read back. That asymmetry is deliberate for now (it is the
 * existing behaviour and narrowing it is a policy decision, not a bug fix), but
 * it is worth an explicit answer before the real import.
 */
class EmployeeResource extends JsonResource
{
    /**
     * Permission gating the pay fields.
     */
    public const VIEW_SALARY = 'hr.employees.viewSalary';

    /**
     * Permission gating the national id.
     */
    public const VIEW_IDENTITY = 'hr.employees.viewIdentity';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'employee_number' => $this->employee_number,
            'name'            => $this->name,
            'national_id'     => $this->when(
                $this->requesterHolds($request, self::VIEW_IDENTITY),
                fn () => $this->national_id,
            ),
            'phone'           => $this->phone,
            'email'           => $this->email,
            'address'         => $this->address,
            'department_id'   => $this->department_id,
            'job_title'       => $this->job_title,
            'hire_date'       => $this->hire_date?->toDateString(),
            'salary'          => $this->when(
                $this->requesterHolds($request, self::VIEW_SALARY),
                fn () => $this->salary,
            ),
            'status'          => $this->status,
            'created_at'      => $this->created_at?->toIso8601String(),
            'updated_at'      => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Whether the caller holds a field-level permission.
     *
     * Fails CLOSED: no authenticated user (a console render, a queued job) means
     * no sensitive field. A super admin passes via hasPermission()'s bypass.
     */
    private function requesterHolds(Request $request, string $permission): bool
    {
        $user = $request->user();

        return $user !== null && $user->hasPermission($permission);
    }
}
