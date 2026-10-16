<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Requests;

use App\Modules\Attendance\Enums\AttendanceDayState;
use App\Modules\Attendance\Requests\Concerns\ReportsOnPeople;
use App\Modules\Attendance\Services\AttendanceReportService;
use App\Modules\Attendance\Support\RowSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * GET /api/attendance/report/employee — legacy's
 * EmployeeAttendanceBreakdownRequest: one employee (trashed ones included —
 * their days are still history), a range or `month` + `year`, at most 92
 * days, and the day filters. Whether the employee is in reach is the
 * service's question, after this.
 */
class EmployeeGridRequest extends FormRequest
{
    use ReportsOnPeople;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->expandMonth();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_id'       => ['required', 'integer', Rule::exists('employees', 'id')],
            ...$this->rangeRules(),
            'status'            => ['sometimes', 'integer', Rule::in(AttendanceDayState::values())],
            'working_days_only' => ['sometimes', 'in:0,1,true,false'],
            'sort'              => ['sometimes', 'string', Rule::in(RowSort::values(AttendanceReportService::GRID_SORTS))],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->capRange($validator));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->sharedMessages(),
            'employee_id.required' => 'الموظف مطلوب.',
            'employee_id.exists'   => 'الموظف المحدد غير موجود.',
            'status.integer'       => 'حالة اليوم المحددة غير صحيحة.',
            'status.in'            => 'حالة اليوم المحددة غير صحيحة.',
            'working_days_only.in' => 'قيمة أيام العمل فقط يجب أن تكون true أو false.',
        ];
    }
}
