<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * EmployeeResource
 *
 * The API contract for an employee, decoupled from the Eloquent model so
 * internal column changes do not leak to clients.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * ⚠️ THIS SHAPE CHANGED IN BATCH 2 — every client must update
 * ───────────────────────────────────────────────────────────────────────────
 *   `name`      -> `first_name` / `middle_name` / `last_name`, plus a read-only
 *                  `full_name` that joins them. There is no `name` key.
 *   `job_title` -> `job_title_id` (+ an embedded `job_title` object when the
 *                  relation is loaded). The free string is gone.
 *   `salary`    -> GONE. It was never a fact for most staff; see the model.
 *   `status`    -> the int 1/2/3 with a `status_label`, not the string
 *                  "active". Same pattern as `level` / `level_label` on
 *                  departments: the int is the machine value to filter on, the
 *                  label is the Arabic name no client should re-derive.
 *
 * ───────────────────────────────────────────────────────────────────────────
 * FIELD-LEVEL GATING (C11)
 * ───────────────────────────────────────────────────────────────────────────
 * Gated keys are OMITTED ENTIRELY, never nulled. An absent key and a null mean
 * different things: null would assert "this employee has no national id", which
 * is a lie about the data rather than a statement about the reader's access.
 *
 * The converse holds for `direct_manager`, and it is why that key is built
 * differently from `department` / `job_title` below: absence is spoken for by
 * the gating rule, so an embedded person object is ALWAYS present and is null
 * when there is nobody (C31). `full_name` and `employee_number` are not gated —
 * see EmployeeStub.
 *
 *   hr.employees.viewIdentity -> national_id
 *   hr.employees.viewSalary   -> the pay block AND every classification axis
 *
 * WHY THE AXES ARE GATED TOO, and this is the part that looks like overreach
 * and is not: activity type, organizational grade, experience level,
 * engineering rank and the four flags are the INPUTS the salary is computed
 * from. Anyone holding them plus the published rate table knows the salary to
 * within a rounding error. Gating the amount while publishing its inputs is
 * theatre.
 *
 * WHY TWO PERMISSIONS AND NOT ONE: pay and identity are different needs held by
 * different people. Payroll needs the salary and has no business with national
 * ids; HR administration needs the national id to file paperwork and may have
 * no business with pay. One permission would force whoever assigns roles to
 * over-grant one in order to get the other.
 *
 * SCOPE: this governs READ exposure only. Who may WRITE these fields is still
 * plain `hr.employees.update`, so a caller can set a salary they cannot read
 * back — tracked as GAP-12, a policy decision awaiting an answer.
 */
class EmployeeResource extends JsonResource
{
    /**
     * Permission gating the pay block and its inputs.
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
            'legacy_number'   => $this->legacy_number,

            // ---- Name ----
            'first_name'  => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name'   => $this->last_name,
            'mother_name' => $this->mother_name,
            'full_name'   => $this->full_name,

            'national_id' => $this->when(
                $this->requesterHolds($request, self::VIEW_IDENTITY),
                fn () => $this->national_id,
            ),

            // ---- Demographics ----
            'gender'                      => $this->gender?->value,
            'gender_label'                => $this->gender?->label(),
            'birth_date'                  => $this->birth_date?->toDateString(),
            'birth_place'                 => $this->birth_place,
            'origin_governorate'          => $this->origin_governorate?->value,
            'origin_governorate_label'    => $this->origin_governorate?->label(),
            'residence_governorate'       => $this->residence_governorate?->value,
            'residence_governorate_label' => $this->residence_governorate?->label(),
            'nationality'                 => $this->nationality,
            'second_nationality'          => $this->second_nationality,

            'marital_status'       => $this->marital_status?->value,
            'marital_status_label' => $this->marital_status?->label(),
            'wives_count'          => $this->wives_count,
            'sons_count'           => $this->sons_count,
            'daughters_count'      => $this->daughters_count,
            // Derived from the two above — the form counts sons and daughters
            // separately, so a stored total would be the same fact twice.
            'children_count'       => $this->children_count,

            // ---- Contact ----
            'phone'           => $this->phone,
            'phone_secondary' => $this->phone_secondary,
            'email'           => $this->email,
            'address'         => $this->address,

            // ---- Military service ----
            'did_military_service'    => $this->did_military_service,
            'military_discharge_date' => $this->military_discharge_date?->toDateString(),

            // ---- Education ----
            // Ungated on purpose. Education is ordinary HR data that appears on
            // a CV, and §5.7 lists the gated axes explicitly without it. It does
            // carry a pay value, but so does hire_date via seniority, and gating
            // every input that money touches would gate the whole record.
            'education_level'       => $this->education_level?->value,
            'education_level_label' => $this->education_level?->label(),
            'qualification'         => $this->qualification,
            'specialization'        => $this->specialization,
            'is_graduated'          => $this->is_graduated,
            'graduation_date'       => $this->graduation_date?->toDateString(),

            // ---- Employment ----
            'department_id'         => $this->department_id,
            'department'            => $this->whenLoaded('department', fn () => [
                'id'    => $this->department->id,
                'name'  => $this->department->name,
                'level' => $this->department->level?->value,
            ]),
            'job_title_id'          => $this->job_title_id,
            'job_title'             => $this->whenLoaded('jobTitle', fn () => [
                'id'   => $this->jobTitle->id,
                'name' => $this->jobTitle->name,
            ]),
            // NULL means "resolve from the org tree", not "has no manager".
            'direct_manager_id'     => $this->direct_manager_id,
            // The same person as an object, so a client does not resolve the id
            // itself. NEVER OMITTED — null is the only empty value (C31), and
            // it carries the id's meaning: no manager BY EXCEPTION, ask the
            // tree. Reading the FK first keeps the common case (almost every
            // employee) free of any relation access at all; the other branch
            // reads a relation every render path eager-loads.
            'direct_manager'        => $this->direct_manager_id === null
                ? null
                : EmployeeStub::from($this->directManager),
            'employment_type'       => $this->employment_type?->value,
            'employment_type_label' => $this->employment_type?->label(),
            'workplace'             => $this->workplace,
            'hire_date'             => $this->hire_date?->toDateString(),
            'termination_date'      => $this->termination_date?->toDateString(),
            'status'                => $this->status?->value,
            'status_label'          => $this->status?->label(),

            // ---- Pay block + its inputs — one gate, because the inputs give
            //      the answer away just as surely as the amount does ----
            ...$this->payBlock($request),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The salary-gated keys, or an empty array for a caller without the
     * permission.
     *
     * Built as one block rather than a dozen separate `when()` calls so that
     * adding a pay-determining column later is one line inside a gate that is
     * already closed — rather than a new field someone forgets to wrap, which
     * is exactly how `salary` and `national_id` shipped ungated in the first
     * place.
     *
     * @return array<string, mixed>
     */
    private function payBlock(Request $request): array
    {
        if (! $this->requesterHolds($request, self::VIEW_SALARY)) {
            return [];
        }

        return [
            'activity_type'               => $this->activity_type?->value,
            'activity_type_label'         => $this->activity_type?->label(),
            'organizational_grade'        => $this->organizational_grade,
            'experience_level'            => $this->experience_level,
            'engineering_rank'            => $this->engineering_rank?->value,
            'engineering_rank_label'      => $this->engineering_rank?->label(),
            'industrial_experience_years' => $this->industrial_experience_years,
            'has_hazard_pay'              => $this->has_hazard_pay,
            'has_scarcity_pay'            => $this->has_scarcity_pay,
            'has_dual_role_pay'           => $this->has_dual_role_pay,
            'distinction_grade'           => $this->distinction_grade,
            'salary_mode'                 => $this->salary_mode?->value,
            'salary_mode_label'           => $this->salary_mode?->label(),
            'agreed_salary'               => $this->agreed_salary,
            'sham_cash_account'           => $this->sham_cash_account,
        ];
    }

    /**
     * Whether the caller holds a field-level permission.
     *
     * FAILS CLOSED: no authenticated user — a console render, a queued job —
     * means no sensitive field. A super admin passes via hasPermission()'s own
     * bypass.
     */
    private function requesterHolds(Request $request, string $permission): bool
    {
        $user = $request->user();

        return $user !== null && $user->hasPermission($permission);
    }
}
