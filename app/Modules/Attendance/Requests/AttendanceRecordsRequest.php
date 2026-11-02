<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Requests;

use App\Modules\Attendance\Enums\RejectionReason;
use App\Modules\Attendance\Services\AttendanceRecordQuery;
use App\Shared\Support\CsvList;
use Illuminate\Validation\Rule;

/**
 * GET /api/attendance/records — legacy's AdminAttendanceRecordsRequest: every
 * filter of the employee's own list, plus WHO (one or several employees,
 * units, a name or staff number) and the refusal reason and receipt window.
 *
 * The id lists accept legacy's CSV (`employee_ids=3,7`) or an array; either
 * way an item that is not an id is a 422 naming it (`employee_ids.1`).
 */
class AttendanceRecordsRequest extends MyAttendanceRecordsRequest
{
    private const ID_LISTS = ['employee_ids', 'department_ids'];

    protected function prepareForValidation(): void
    {
        foreach (self::ID_LISTS as $key) {
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
            ...parent::rules(),
            'employee_id'      => ['sometimes', 'integer', 'min:1'],
            'employee_ids'     => ['sometimes', 'array'],
            'employee_ids.*'   => ['integer', 'min:1'],
            'department_id'    => ['sometimes', 'integer', 'min:1'],
            'department_ids'   => ['sometimes', 'array'],
            'department_ids.*' => ['integer', 'min:1'],
            'search'           => ['sometimes', 'nullable', 'string', 'max:100'],
            'rejection_reason' => ['sometimes', 'integer', Rule::enum(RejectionReason::class)],
            'created_from'     => ['sometimes', 'date'],
            'created_to'       => ['sometimes', 'date', 'after_or_equal:created_from'],
            // Legacy sorts the oversight list by the person too.
            'sort'             => ['sometimes', 'string', Rule::in(AttendanceRecordQuery::sortKeys(oversight: true))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'employee_id.integer'       => 'معرّف الموظف غير صالح.',
            'employee_ids.*.integer'    => 'قائمة الموظفين تحتوي معرّفاً غير صالح.',
            'employee_ids.*.min'        => 'قائمة الموظفين تحتوي معرّفاً غير صالح.',
            'department_id.integer'     => 'معرّف الوحدة التنظيمية غير صالح.',
            'department_ids.*.integer'  => 'قائمة الوحدات تحتوي معرّفاً غير صالح.',
            'department_ids.*.min'      => 'قائمة الوحدات تحتوي معرّفاً غير صالح.',
            'search.max'                => 'نص البحث طويل جداً.',
            'rejection_reason.integer'  => 'سبب الرفض غير صالح.',
            'rejection_reason.enum'     => 'سبب الرفض غير صالح.',
            'created_from.date'         => 'تاريخ بداية الاستلام غير صحيح.',
            'created_to.date'           => 'تاريخ نهاية الاستلام غير صحيح.',
            'created_to.after_or_equal' => 'تاريخ نهاية الاستلام يجب أن يكون مساوياً أو بعد تاريخ البداية.',
        ];
    }
}
