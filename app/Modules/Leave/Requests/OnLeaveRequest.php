<?php

declare(strict_types=1);

namespace App\Modules\Leave\Requests;

use App\Shared\Support\CsvList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/leave/on-leave — legacy's ApprovedLeaveUsersRequest: one day
 * (`date`, today in the attendance timezone by default), the leave's type(s),
 * request or HR excuse, the person filters attendance's lists take, and a
 * sort. Id lists take legacy's CSV or an array.
 */
class OnLeaveRequest extends FormRequest
{
    public const SORTS = ['start_date', '-start_date', 'end_date', '-end_date', 'employee_name', '-employee_name'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['leave_type_ids', 'employee_ids', 'department_ids'] as $key) {
            if ($this->has($key)) {
                $this->merge([$key => CsvList::parse($this->input($key))]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date'             => ['sometimes', 'date_format:Y-m-d'],
            'leave_type_id'    => ['sometimes', 'integer', Rule::exists('leave_types', 'id')],
            'leave_type_ids'   => ['sometimes', 'array'],
            'leave_type_ids.*' => ['integer', Rule::exists('leave_types', 'id')],
            'is_excuse'        => ['sometimes', 'boolean'],
            'employee_id'      => ['sometimes', 'integer'],
            'employee_ids'     => ['sometimes', 'array'],
            'employee_ids.*'   => ['integer'],
            'department_id'    => ['sometimes', 'integer', Rule::exists('departments', 'id')],
            'department_ids'   => ['sometimes', 'array'],
            'department_ids.*' => ['integer', Rule::exists('departments', 'id')],
            'search'           => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort'             => ['sometimes', 'string', Rule::in(self::SORTS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.date_format'         => 'التاريخ يجب أن يكون بالصيغة YYYY-MM-DD.',
            'leave_type_id.*'          => 'نوع الإجازة غير صالح.',
            'leave_type_ids.*.*'       => 'قائمة الأنواع تحتوي نوعاً غير صالح.',
            'is_excuse.boolean'        => 'قيمة العذر غير صالحة.',
            'employee_id.integer'      => 'الموظف غير صالح.',
            'employee_ids.*.integer'   => 'قائمة الموظفين تحتوي قيمة غير صالحة.',
            'department_id.*'          => 'الوحدة غير صالحة.',
            'department_ids.*.*'       => 'قائمة الوحدات تحتوي وحدة غير صالحة.',
            'search.max'               => 'نص البحث طويل جداً.',
            'sort.in'                  => 'حقل الترتيب المحدد غير مدعوم.',
        ];
    }
}
