<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use App\Modules\HR\Enums\AssignmentReason;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Resources\EmployeeResource;
use App\Modules\HR\Support\HireDateGuard;
use App\Shared\Support\RequestRowScopes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * EmployeeAssignmentRequest
 *
 * Validates recording a posting change — a transfer, promotion, title change or
 * restructure — against an employee.
 *
 * `reason` IS REQUIRED AND HAS NO DEFAULT, which is P3 applied to the one field
 * that carries the meaning of the whole row. A default would be a guess about
 * why someone moved, written into an append-only history that cannot be edited
 * afterwards; and it would be the wrong guess most of the time, since the
 * caller reaching this endpoint (rather than simply updating the employee) is
 * precisely the caller who knows the answer.
 *
 * `from_date` may be in the past — back-dating is ordinary here, because HR
 * records a transfer after the paperwork clears rather than on the day it took
 * effect. It may NOT be in the future: a posting that has not started yet would
 * be returned as the current one by `to_date IS NULL`, and the employee's
 * department pointer would move before the person did.
 */
class EmployeeAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'department_id' => ['sometimes', 'nullable', 'integer',
                Rule::exists('departments', 'id')->whereNull('deleted_at')],
            'job_title_id'  => ['sometimes', 'nullable', 'integer',
                Rule::exists('job_titles', 'id')->whereNull('deleted_at')],
            'organizational_grade' => $this->mayWriteGrade()
                ? ['sometimes', 'nullable', 'integer', 'between:1,5']
                : ['missing'],

            'from_date' => ['required', 'date', 'before_or_equal:today'],
            'reason'    => ['required', 'integer', Rule::enum(AssignmentReason::class)],
            'notes'     => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * من لا يرى لا يكتُب, AT THE SECOND DOOR (GAP-12). `organizational_grade`
     * is a pay axis — `EmployeeRequest::SALARY_GATED_FIELDS` lists it — and a
     * posting copies it onto `employees.organizational_grade` through
     * `EmployeeAssignment::syncEmployeePointer()`. Without this, a caller
     * refused the field on PATCH /employees could set it here instead.
     *
     * Per row, by the same rule as `EmployeeRequest::gateSensitiveWrites()`:
     * the viewSalary grant must reach every unit the posting touches — where
     * the employee is now, and where a named `department_id` puts them. `missing`
     * rather than `prohibited`, so `null` cannot clear a grade either. An
     * omitted grade carries forward unchanged (EmployeeAssignmentService), so a
     * caller without the grant can still record a transfer.
     */
    private function mayWriteGrade(): bool
    {
        $scope = RequestRowScopes::for(request(), EmployeeResource::VIEW_SALARY);

        $employee = $this->route('employee');
        $units = [];

        if ($employee instanceof Employee) {
            $units[] = $employee->department_id === null ? null : (int) $employee->department_id;
        }

        if ($this->has('department_id')) {
            $raw = $this->input('department_id');
            $units[] = is_numeric($raw) ? (int) $raw : null;
        }

        foreach ($units as $unit) {
            if (! $scope->covers($unit)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A posting may not start before the person was hired (GAP-19,
     * HireDateGuard) — a field-level 422 on `from_date` ahead of the model
     * hook's refusal. Without it, back-dating a transfer past the hire date
     * amended the opening posting in place and left the history saying the
     * person held a unit before they worked here.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $employee = $this->route('employee');

                if ($validator->errors()->isNotEmpty() || ! $employee instanceof Employee) {
                    return;
                }

                $violation = (new HireDateGuard)->postingViolation(
                    $employee->hire_date,
                    Carbon::parse($this->input('from_date')),
                );

                if ($violation !== null) {
                    $validator->errors()->add($violation->field, $violation->message);
                }
            },
        ];
    }

    /**
     * ROW SCOPE on the DESTINATION (GAP-3) — the same rule as
     * `EmployeeRequest::passedValidation()`, for the other door a transfer
     * comes through. `can:recordAssignment,employee` has checked the person;
     * a posting that names a unit must also land inside the caller's reach.
     * An omitted unit carries forward (see EmployeeAssignmentService) and so
     * moves nobody; neither does restating the current one.
     */
    protected function passedValidation(): void
    {
        $validated = $this->validated();
        $employee = $this->route('employee');

        if (! $employee instanceof Employee || ! array_key_exists('department_id', $validated)) {
            return;
        }

        $target = $validated['department_id'] === null ? null : (int) $validated['department_id'];
        $current = $employee->department_id === null ? null : (int) $employee->department_id;

        if ($target === $current) {
            return;
        }

        Gate::forUser($this->user())->authorize('placeIn', [Employee::class, $target, 'hr.assignments.create']);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'department_id.exists'   => 'الوحدة التنظيمية المحددة غير موجودة أو تم حذفها.',
            'job_title_id.exists'    => 'المسمى الوظيفي المحدد غير موجود أو تم حذفه.',
            'organizational_grade.between' => 'السوية التنظيمية يجب أن تكون بين 1 و 5.',
            'organizational_grade.missing' => 'لا تملك صلاحية تعديل حقول الرواتب ومعاييرها.',
            'from_date.required'     => 'تاريخ بدء التعيين مطلوب.',
            'from_date.before_or_equal' => 'تاريخ بدء التعيين لا يمكن أن يكون في المستقبل.',
            'reason.required'        => 'سبب التعيين مطلوب.',
            'reason.enum'            => 'سبب التعيين المحدد غير معرّف.',
        ];
    }
}
