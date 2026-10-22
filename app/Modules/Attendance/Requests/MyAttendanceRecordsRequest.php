<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Requests;

use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Attendance\Services\AttendanceRecordQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * GET /api/me/attendance — legacy's MyAttendanceRecordsRequest filters and
 * messages. The sort keys come from AttendanceRecordQuery, which owns them
 * (C30); an unknown one is a 422.
 */
class MyAttendanceRecordsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $flag = ['sometimes', 'in:0,1,true,false'];

        return [
            'date'             => ['sometimes', 'date'],
            'from'             => ['sometimes', 'date'],
            'to'               => ['sometimes', 'date', 'after_or_equal:from'],
            'month'            => ['sometimes', 'integer', 'min:1', 'max:12'],
            'year'             => ['sometimes', 'integer', 'min:2000', 'max:2100'],
            'type'             => ['sometimes', 'integer', Rule::in(AttendanceEventType::values())],
            'missing_checkout' => $flag,
            'is_corrected'     => $flag,
            'rejected'         => $flag,
            'work_hours_min'   => ['sometimes', 'numeric', 'min:0'],
            'work_hours_max'   => ['sometimes', 'numeric', 'min:0'],
            'sort'             => ['sometimes', 'string', Rule::in(AttendanceRecordQuery::sortKeys())],
            'per_page'         => ['sometimes', 'integer'],
            'page'             => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $min = $this->input('work_hours_min');
            $max = $this->input('work_hours_max');

            if (is_numeric($min) && is_numeric($max) && (float) $max < (float) $min) {
                $validator->errors()->add('work_hours_max', 'الحد الأقصى لساعات العمل يجب أن يكون أكبر من أو يساوي الحد الأدنى.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.date'              => 'التاريخ المحدد غير صحيح.',
            'from.date'              => 'تاريخ البداية غير صحيح.',
            'to.date'                => 'تاريخ النهاية غير صحيح.',
            'to.after_or_equal'      => 'تاريخ النهاية يجب أن يكون مساوياً أو بعد تاريخ البداية.',
            'month.integer'          => 'الشهر يجب أن يكون رقماً بين 1 و 12.',
            'month.min'              => 'الشهر يجب أن يكون رقماً بين 1 و 12.',
            'month.max'              => 'الشهر يجب أن يكون رقماً بين 1 و 12.',
            'year.integer'           => 'السنة يجب أن تكون رقماً صحيحاً.',
            'year.min'               => 'السنة غير صحيحة.',
            'year.max'               => 'السنة غير صحيحة.',
            'type.integer'           => 'نوع السجل يجب أن يكون حضوراً أو انصرافاً.',
            'type.in'                => 'نوع السجل يجب أن يكون حضوراً أو انصرافاً.',
            'missing_checkout.in'    => 'قيمة الانصراف المفقود يجب أن تكون true أو false.',
            'is_corrected.in'        => 'قيمة التصحيح يجب أن تكون true أو false.',
            'rejected.in'            => 'قيمة الرفض يجب أن تكون true أو false.',
            'work_hours_min.numeric' => 'الحد الأدنى لساعات العمل يجب أن يكون رقماً.',
            'work_hours_max.numeric' => 'الحد الأقصى لساعات العمل يجب أن يكون رقماً.',
            'sort.in'                => 'حقل الترتيب المحدد غير مدعوم.',
        ];
    }
}
