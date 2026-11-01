<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Requests;

use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Attendance\Enums\LocationReviewStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BulkApproveLocationReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'record_ids' => ['sometimes', 'array', 'min:1', 'max:100'],
            'record_ids.*' => ['integer', 'min:1', 'distinct'],
            'matching' => ['sometimes', 'array:date,from,to,month,year,type,employee_id,employee_ids,department_id,department_ids,search,missing_checkout,is_corrected,work_hours_min,work_hours_max,location_review_status,open_location_review'],
            'matching.date' => ['sometimes', 'date'],
            'matching.from' => ['sometimes', 'date'],
            'matching.to' => ['sometimes', 'date', 'after_or_equal:matching.from'],
            'matching.month' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'matching.year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
            'matching.type' => ['sometimes', 'integer', Rule::in(AttendanceEventType::values())],
            'matching.employee_id' => ['sometimes', 'integer', 'min:1'],
            'matching.employee_ids' => ['sometimes', 'array'],
            'matching.employee_ids.*' => ['integer', 'min:1'],
            'matching.department_id' => ['sometimes', 'integer', 'min:1'],
            'matching.department_ids' => ['sometimes', 'array'],
            'matching.department_ids.*' => ['integer', 'min:1'],
            'matching.search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'matching.missing_checkout' => ['sometimes', 'in:0,1,true,false'],
            'matching.is_corrected' => ['sometimes', 'in:0,1,true,false'],
            'matching.work_hours_min' => ['sometimes', 'numeric', 'min:0'],
            'matching.work_hours_max' => ['sometimes', 'numeric', 'min:0'],
            // Approval by filter only ever approves in-process rows. A screen
            // filtered to another status must not send "approve everything
            // matching" and have different rows approved than the ones shown.
            'matching.location_review_status' => ['sometimes', Rule::in([LocationReviewStatus::InProcess->value])],
            'matching.open_location_review' => ['sometimes', 'in:0,1,true,false'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'matching.location_review_status.in' => 'الاعتماد حسب الفلتر يشمل السجلات قيد المعالجة فقط؛ حدّد سجلات المراجعة يدوياً.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasIds = array_key_exists('record_ids', $this->all());
            $hasMatching = array_key_exists('matching', $this->all());
            if ($hasIds === $hasMatching) {
                $validator->errors()->add('record_ids', 'أرسل معرّفات السجلات أو مرشح المطابقة، وليس كليهما.');
            }
        });
    }
}
