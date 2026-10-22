<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use App\Modules\HR\Enums\ActivityType;
use App\Modules\HR\Enums\EducationLevel;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Enums\EmploymentType;
use App\Modules\HR\Enums\EngineeringRank;
use App\Modules\HR\Enums\Gender;
use App\Modules\HR\Enums\Governorate;
use App\Modules\HR\Enums\MaritalStatus;
use App\Modules\HR\Enums\SalaryMode;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Resources\EmployeeResource;
use App\Modules\HR\Support\EmployeeClassificationGuard;
use App\Modules\HR\Support\HireDateGuard;
use App\Shared\Support\RequestRowScopes;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * EmployeeRequest
 *
 * Validates the payload for creating and updating an employee, and is the
 * FRIENDLY FRONT DOOR over EmployeeClassificationGuard — the same class the
 * Employee model's `saving` hook calls.
 *
 * WHY THE RULES ARE NOT IMPLEMENTED HERE: if axis exclusivity and the pay-track
 * pairing lived in this file they would guard HTTP and nothing else. The Batch 4
 * importer, the seeders, tinker and every future job write around a
 * FormRequest, and this project has already paid for that lesson once — tier
 * rules that lived only in DepartmentRequest let the seeder create four roots.
 * The guard owns the rules; this class translates its violations into
 * field-level Arabic 422s (C4/C5).
 *
 * ✅ FIELD-LEVEL WRITE ACCESS IS GOVERNED HERE (GAP-12, closed 2026-09-23).
 * A caller without `hr.employees.viewSalary` may not send `agreed_salary`,
 * `sham_cash_account` or any classification axis; without
 * `hr.employees.viewIdentity` they may not send `national_id`. The rule is
 * من لا يرى لا يكتُب, and `gateSensitiveWrites()` below is where it lives.
 * Until that existed, a caller could set values the API then refused to show
 * them.
 *
 * NULLABILITY DIFFERS FROM THE SCHEMA ON PURPOSE. Almost every column is
 * nullable so the Batch 4 importer can land a flawed row and REPORT it (§7.4)
 * rather than abort or invent a value. The API front door is stricter: a human
 * creating an employee through the UI is asked for the name parts and the
 * gender, because they are standing in front of the person.
 */
class EmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Coarse-grained access is route middleware's job (auth:sanctum +
        // permission:hr.employees.*). Field-level validation lives here.
        return true;
    }

    /**
     * Columns a caller may not WRITE without `hr.employees.viewSalary`.
     *
     * ⚠️ THIS LIST MIRRORS `EmployeeResource::payBlock()` AND MUST NOT DRIFT
     * FROM IT. A column that is gated on read and open on write is GAP-12 all
     * over again, one field at a time. `EmployeeWriteGateTest` renders the
     * Resource with and without the permission and asserts the two sets match,
     * so adding a pay column to one side and forgetting the other is a failing
     * test rather than a silent hole.
     *
     * The `_label` keys the Resource also hides are absent here on purpose:
     * they are derived output, not input, and nothing can write them.
     *
     * @var array<int, string>
     */
    public const SALARY_GATED_FIELDS = [
        'activity_type',
        'organizational_grade',
        'experience_level',
        'engineering_rank',
        'industrial_experience_years',
        'has_hazard_pay',
        'has_scarcity_pay',
        'has_dual_role_pay',
        'distinction_grade',
        'salary_mode',
        'agreed_salary',
        'sham_cash_account',
        'payroll_location',
    ];

    /**
     * Columns a caller may not WRITE without `hr.employees.viewIdentity`.
     *
     * @var array<int, string>
     */
    public const IDENTITY_GATED_FIELDS = [
        'national_id',
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->gateSensitiveWrites($this->fieldRules());
    }

    /**
     * ✅ GAP-12, closed 2026-09-23: من لا يرى لا يكتُب.
     *
     * A caller who cannot READ a field may not WRITE it either. Until this
     * existed, `hr.employees.update` alone could set a salary, a Sham Cash
     * account and every classification axis — values the same caller was then
     * refused on the way back out. Writing a number you are not allowed to see
     * is not a lesser act than reading it: it is how a payslip gets changed by
     * someone who cannot be shown the payslip.
     *
     * WHY `missing` AND NOT `prohibited`. `prohibited` passes when the field is
     * present but empty — so `agreed_salary: null` would sail through and CLEAR
     * a salary the caller cannot read. `missing` requires the key to be absent
     * from the payload altogether, which is the actual rule.
     *
     * WHY 422 AND NOT 403. The refusal names the field. A blanket 403 on the
     * request would say only "no", leaving a client to guess which of forty
     * keys caused it — and the frontend contract is already "don't render,
     * don't send", so a payload that reaches here carrying a gated field is a
     * client bug that deserves to be pointed at.
     *
     * ⚙️ PER ROW SINCE GAP-3, exactly as the read side is. "May write pay" is
     * "the grant carrying viewSalary reaches this employee" — and on a write
     * that means EVERY unit the row sits in, before and after: the one they are
     * in now, and the one a create or a transfer puts them in. Checking only
     * the destination would let a section-scoped payroll grant rewrite the pay
     * of someone from another division while moving them in; checking only the
     * current unit would let it set pay on someone being moved out. A unit
     * reached by `hr.employees.update` but not by `viewSalary` is refused here
     * — that is the case this gate exists for.
     *
     * The destination is read RAW, because rules() runs before validation. A
     * malformed `department_id` counts as no unit (reached only company-wide),
     * and gets its own 422 from its own rule.
     *
     * FAILS CLOSED: no authenticated user reaches nothing, exactly as
     * `EmployeeResource::requesterHolds()` decides it. A super admin reaches
     * everything.
     *
     * @param  array<string, array<int, mixed>>  $rules
     * @return array<string, array<int, mixed>>
     */
    private function gateSensitiveWrites(array $rules): array
    {
        $gates = [
            EmployeeResource::VIEW_SALARY   => self::SALARY_GATED_FIELDS,
            EmployeeResource::VIEW_IDENTITY => self::IDENTITY_GATED_FIELDS,
        ];

        $units = $this->unitsTouched();

        foreach ($gates as $permission => $fields) {
            // The BASE request, not `$this`: a FormRequest has its own
            // attribute bag, and memoizing there would walk the tree again for
            // the scopes the policy and the controller's warm-up already hold.
            $scope = RequestRowScopes::for(request(), $permission);

            if (array_filter($units, static fn (?int $unit): bool => ! $scope->covers($unit)) === []) {
                continue;
            }

            foreach ($fields as $field) {
                // Replaces the field's rules rather than appending to them: the
                // only acceptable state is absent, so every other rule on it is
                // unreachable and keeping them would just be noise in the 422.
                $rules[$field] = ['missing'];
            }
        }

        return $rules;
    }

    /**
     * Every unit this write leaves the employee in or takes them out of: the
     * current one on an update, plus the requested one when the payload names
     * a unit. A create has only the requested one — no unit if it names none.
     *
     * @return array<int, int|null>
     */
    private function unitsTouched(): array
    {
        $employee = $this->subject();
        $units = [];

        if ($employee instanceof Employee) {
            $units[] = $employee->department_id === null ? null : (int) $employee->department_id;
        }

        if (! $employee instanceof Employee || $this->has('department_id')) {
            $raw = $this->input('department_id');
            $units[] = is_numeric($raw) ? (int) $raw : null;
        }

        return array_values(array_unique($units, SORT_REGULAR));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function fieldRules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';
        $id       = $this->subject()?->id;

        return [
            // ── Identity ──────────────────────────────────────────────────
            //
            // Optional on create — the model generates one. A supplied value is
            // respected and only checked for uniqueness (project owner,
            // 2026-09-13), which is why `filled` rather than `nullable` guards
            // the update: clearing it would violate a NOT NULL column.
            //
            // NOTE: no ->whereNull('deleted_at') on any unique rule in this
            // file. The indexes count trashed rows, so the rules must too, or
            // validation passes and the insert then fails with a 500 where the
            // caller should have had a 422. That is C24 read in the correct
            // direction — the rule must match the index, not a convention.
            'employee_number' => ['sometimes', $creating ? 'nullable' : 'filled', 'string', 'max:50',
                Rule::unique('employees', 'employee_number')->ignore($id)],
            'national_id'     => ['sometimes', 'nullable', 'string', 'max:50',
                Rule::unique('employees', 'national_id')->ignore($id)],

            'first_name'  => [$required, 'string', 'max:255'],
            'middle_name' => [$required, 'string', 'max:255'],
            'last_name'   => [$required, 'string', 'max:255'],
            'mother_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            // اللقب — the kunya (أبو عبد الرحمن); identity, ungated (2026-10-14).
            'nickname'    => ['sometimes', 'nullable', 'string', 'max:100'],

            'gender'                => [$required, 'integer', Rule::enum(Gender::class)],
            'birth_date'            => ['sometimes', 'nullable', 'date', 'before:today'],
            'birth_place'           => ['sometimes', 'nullable', 'string', 'max:255'],
            'origin_governorate'    => ['sometimes', 'nullable', 'integer', Rule::enum(Governorate::class)],
            'residence_governorate' => ['sometimes', 'nullable', 'integer', Rule::enum(Governorate::class)],
            'nationality'           => ['sometimes', 'string', 'max:60'],
            'second_nationality'    => ['sometimes', 'nullable', 'string', 'max:60'],

            'marital_status'  => ['sometimes', 'nullable', 'integer', Rule::enum(MaritalStatus::class)],
            'wives_count'     => ['sometimes', 'integer', 'min:0', 'max:4'],
            'sons_count'      => ['sometimes', 'integer', 'min:0', 'max:50'],
            'daughters_count' => ['sometimes', 'integer', 'min:0', 'max:50'],

            // ── Contact ───────────────────────────────────────────────────
            'phone'           => ['sometimes', 'nullable', 'string', 'max:50'],
            'phone_secondary' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email'           => ['sometimes', 'nullable', 'email', 'max:255'],
            'address'         => ['sometimes', 'nullable', 'string', 'max:1000'],

            // ── Military service ──────────────────────────────────────────
            'did_military_service'    => ['sometimes', 'nullable', 'boolean'],
            'military_discharge_date' => ['sometimes', 'nullable', 'date'],

            // ── Education ─────────────────────────────────────────────────
            'education_level' => ['sometimes', 'nullable', 'integer', Rule::enum(EducationLevel::class)],
            'qualification'   => ['sometimes', 'nullable', 'string', 'max:255'],
            'specialization'  => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_graduated'    => ['sometimes', 'nullable', 'boolean'],
            'graduation_date' => ['sometimes', 'nullable', 'date'],

            // ── Classification — pay-determining (§5.3) ───────────────────
            'activity_type'        => ['sometimes', 'nullable', 'integer', Rule::enum(ActivityType::class)],
            'organizational_grade' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            'experience_level'     => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            'engineering_rank'     => ['sometimes', 'nullable', 'integer', Rule::enum(EngineeringRank::class)],
            'industrial_experience_years' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:60'],
            'has_hazard_pay'       => ['sometimes', 'boolean'],
            'has_scarcity_pay'     => ['sometimes', 'boolean'],
            'has_dual_role_pay'    => ['sometimes', 'boolean'],

            // التمييز بحسب التقييم. Bounded only by the column's own range on
            // purpose: the spec types it as a tinyint without stating a scale,
            // and the performance-review module that SETS it has not been built,
            // so any tighter bound here would be invented rather than observed.
            // Flagged for HR to pin down before the review cycle ships.
            'distinction_grade' => ['sometimes', 'nullable', 'integer', 'between:0,255'],

            // ── Employment ────────────────────────────────────────────────
            // exists + whereNull: a bare exists accepts a trashed row (C24),
            // which would leave department_id populated while the relation
            // resolves to null.
            'department_id' => ['sometimes', 'nullable', 'integer',
                Rule::exists('departments', 'id')->whereNull('deleted_at')],
            'job_title_id'  => ['sometimes', 'nullable', 'integer',
                Rule::exists('job_titles', 'id')->whereNull('deleted_at')],
            'employment_type'  => ['sometimes', 'nullable', 'integer', Rule::enum(EmploymentType::class)],
            'workplace'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'hire_date'        => ['sometimes', 'nullable', 'date'],
            'termination_date' => ['sometimes', 'nullable', 'date'],
            'status'           => ['sometimes', 'integer', Rule::enum(EmployeeStatus::class)],

            // ── Pay reference (§5.6) ──────────────────────────────────────
            // The 16-digit shape is checked here for a friendly message AND in
            // the guard for every other writer. That is not duplication of a
            // BUSINESS rule — the guard owns it; this is the same rule stated in
            // the validator's vocabulary so the error lands on the field.
            'sham_cash_account' => ['sometimes', 'nullable', 'string', 'digits:16',
                Rule::unique('employees', 'sham_cash_account')->ignore($id)],
            'salary_mode'   => ['sometimes', 'integer', Rule::enum(SalaryMode::class)],
            'agreed_salary' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
            // مكان الراتب — the payroll cost centre; free text until Finance
            // has a table of them. Pay-gated: SALARY_GATED_FIELDS (2026-10-14).
            'payroll_location' => ['sometimes', 'nullable', 'string', 'max:150'],
        ];
    }

    /**
     * The refusal message for a gated field a caller may not write.
     *
     * Generated from the same two constants the gate reads, so a field added to
     * either list cannot end up refused with Laravel's English default —
     * which is what a hand-maintained list of thirteen `.missing` keys would
     * eventually produce.
     *
     * @return array<string, string>
     */
    private function gatedFieldMessages(): array
    {
        $messages = [];

        foreach (self::SALARY_GATED_FIELDS as $field) {
            $messages["{$field}.missing"] = 'لا تملك صلاحية تعديل حقول الرواتب ومعاييرها.';
        }

        foreach (self::IDENTITY_GATED_FIELDS as $field) {
            $messages["{$field}.missing"] = 'لا تملك صلاحية تعديل الرقم الوطني.';
        }

        return $messages;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->gatedFieldMessages(),

            'first_name.required'  => 'الاسم الأول مطلوب.',
            'middle_name.required' => 'اسم الأب مطلوب.',
            'last_name.required'   => 'الكنية مطلوبة.',
            'gender.required'      => 'الجنس مطلوب.',
            'gender.enum'          => 'الجنس المحدد غير معرّف.',
            'birth_date.before'    => 'تاريخ الميلاد يجب أن يكون في الماضي.',

            'employee_number.unique'   => 'الرقم الوظيفي مستخدم بالفعل.',
            'employee_number.filled'   => 'الرقم الوظيفي لا يمكن أن يكون فارغاً.',
            'national_id.unique'       => 'الرقم الوطني مستخدم بالفعل.',

            'marital_status.enum'      => 'الحالة الاجتماعية المحددة غير معرّفة.',
            'education_level.enum'     => 'المرحلة الدراسية المحددة غير معرّفة.',
            'activity_type.enum'       => 'نوع النشاط المحدد غير معرّف.',
            'engineering_rank.enum'    => 'الرتبة الهندسية المحددة غير معرّفة.',
            'employment_type.enum'     => 'نوع التوظيف المحدد غير معرّف.',
            'status.enum'              => 'حالة الموظف المحددة غير معرّفة.',
            'salary_mode.enum'         => 'نظام الراتب المحدد غير معرّف.',
            'origin_governorate.enum'  => 'المحافظة المحددة غير معرّفة.',
            'residence_governorate.enum' => 'محافظة الإقامة المحددة غير معرّفة.',

            'organizational_grade.between' => 'السوية التنظيمية يجب أن تكون بين 1 و 5.',
            'experience_level.between'     => 'مستوى الخبرة يجب أن يكون بين 1 و 5.',

            'department_id.exists'     => 'الوحدة التنظيمية المحددة غير موجودة أو تم حذفها.',
            'job_title_id.exists'      => 'المسمى الوظيفي المحدد غير موجود أو تم حذفه.',

            'sham_cash_account.digits' => 'رقم الشام كاش يجب أن يتكون من 16 رقماً.',
            'sham_cash_account.unique' => 'رقم الشام كاش مستخدم بالفعل.',
            'nickname.max'             => 'اللقب يجب ألا يتجاوز 100 حرف.',
            'payroll_location.max'     => 'مكان الراتب يجب ألا يتجاوز 150 حرفاً.',
            'agreed_salary.numeric'    => 'الراتب المتفق عليه يجب أن يكون رقماً.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->applyClassificationGuard($validator),
            fn (Validator $validator) => $this->applyHireDateGuard($validator),
        ];
    }

    /**
     * Front door over HireDateGuard (GAP-19): a `hire_date` correction the
     * posting history cannot follow is a field-level 422 on `hire_date`,
     * rather than the model hook's message with no field attached.
     */
    protected function applyHireDateGuard(Validator $validator): void
    {
        $employee = $this->subject();

        if ($validator->errors()->isNotEmpty() || $employee === null || ! $this->filled('hire_date')) {
            return;
        }

        $violation = (new HireDateGuard)->hireDateViolation($employee, Carbon::parse($this->input('hire_date')));

        if ($violation !== null) {
            $validator->errors()->add($violation->field, $violation->message);
        }
    }

    /**
     * Front door over EmployeeClassificationGuard: run every invariant and
     * surface each violation as a field-level 422 error.
     */
    protected function applyClassificationGuard(Validator $validator): void
    {
        // If the fields the guard reads are already invalid, its output would
        // only pile a confusing second error on top of a clear first one.
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $violations = (new EmployeeClassificationGuard)->violations($this->effectiveAttributes());

        foreach ($violations as $violation) {
            $validator->errors()->add($violation->field, $violation->message);
        }
    }

    /**
     * The attributes this row will END UP STORING.
     *
     * The merge is what makes the guard correct on a PATCH. Submitting only
     * `activity_type` must be judged against the engineering rank the row
     * ALREADY holds — otherwise changing an engineer to إداري would pass
     * validation here, having seen no rank at all, and then be refused by the
     * model hook as a 422 with no field attached to it.
     *
     * Stored values are read raw, because that is what the guard compares and
     * what the row actually holds. A brand-new employee is represented by an
     * unsaved model, so the `$attributes` defaults (status, salary_mode, the
     * counters) are present exactly as they will be on insert.
     *
     * @return array<string, mixed>
     */
    protected function effectiveAttributes(): array
    {
        $stored = ($this->subject() ?? new Employee)->getAttributes();

        // only() omits keys absent from the payload, so an untouched field
        // keeps its stored value rather than being read as null.
        return array_merge($stored, $this->only(array_keys($this->rules())));
    }

    /**
     * ROW SCOPE on the DESTINATION (GAP-3).
     *
     * `can:update,employee` on the route has already checked that the person
     * being edited is in scope. What it cannot see is where they are GOING: a
     * create files a new hire into a unit, and an update may transfer someone.
     * Both must land inside the caller's reach, or a manager could push a
     * person out into another division — or a clerk file new hires anywhere.
     *
     * AFTER VALIDATION, deliberately: by now `department_id` is known to be an
     * integer naming a live unit (or null), so the scope check judges a real
     * value and a malformed one gets its field-level 422 instead of a 403.
     *
     * A create with no unit is a placement into NO unit, which only a
     * company-wide grant may make. An update that does not mention the unit,
     * or restates the current one, moves nobody and is not checked again.
     */
    protected function passedValidation(): void
    {
        $validated = $this->validated();
        $employee = $this->subject();

        if ($employee instanceof Employee) {
            if (! array_key_exists('department_id', $validated)) {
                return;
            }

            $target = $validated['department_id'] === null ? null : (int) $validated['department_id'];
            $current = $employee->department_id === null ? null : (int) $employee->department_id;

            if ($target === $current) {
                return;
            }

            Gate::forUser($this->user())->authorize('placeIn', [Employee::class, $target, 'hr.employees.update']);

            return;
        }

        $target = ($validated['department_id'] ?? null) === null ? null : (int) $validated['department_id'];

        Gate::forUser($this->user())->authorize('placeIn', [Employee::class, $target, 'hr.employees.create']);
    }

    /**
     * The employee being updated, or null on create.
     */
    protected function subject(): ?Employee
    {
        $employee = $this->route('employee');

        return $employee instanceof Employee ? $employee : null;
    }
}
