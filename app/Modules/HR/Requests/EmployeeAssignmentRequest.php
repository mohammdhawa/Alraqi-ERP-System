<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use App\Modules\HR\Enums\AssignmentReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'organizational_grade' => ['sometimes', 'nullable', 'integer', 'between:1,5'],

            'from_date' => ['required', 'date', 'before_or_equal:today'],
            'reason'    => ['required', 'integer', Rule::enum(AssignmentReason::class)],
            'notes'     => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
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
            'from_date.required'     => 'تاريخ بدء التعيين مطلوب.',
            'from_date.before_or_equal' => 'تاريخ بدء التعيين لا يمكن أن يكون في المستقبل.',
            'reason.required'        => 'سبب التعيين مطلوب.',
            'reason.enum'            => 'سبب التعيين المحدد غير معرّف.',
        ];
    }
}
