<?php

declare(strict_types=1);

namespace App\Modules\Leave\Requests;

use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Services\LeaveRequestQuery;
use App\Shared\Support\CsvList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The leave filters legacy's own list and register share (its
 * LeaveRequestsIndexRequest): status(es), type(s), year, month, an overlap
 * window, whether HR filed it, and a sort. The id lists take legacy's CSV or
 * an array. Unknown values are a 422 (C30), never a silent default.
 */
class LeaveRequestsFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['statuses', 'leave_type_ids'] as $key) {
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
        $statuses = array_map(static fn (LeaveStatus $status): int => $status->value, LeaveStatus::cases());

        return [
            'status'           => ['sometimes', 'integer', Rule::in($statuses)],
            'statuses'         => ['sometimes', 'array'],
            'statuses.*'       => ['integer', Rule::in($statuses)],
            'leave_type_id'    => ['sometimes', 'integer', 'min:1'],
            'leave_type_ids'   => ['sometimes', 'array'],
            'leave_type_ids.*' => ['integer', 'min:1'],
            'year'             => ['sometimes', 'integer', 'min:2000', 'max:2100'],
            'month'            => ['sometimes', 'integer', 'min:1', 'max:12'],
            'date_from'        => ['sometimes', 'date'],
            'date_to'          => ['sometimes', 'date'],
            'is_excuse'        => ['sometimes', 'boolean'],
            'sort'             => ['sometimes', 'string', Rule::in(LeaveRequestQuery::sortKeys())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in'          => 'حالة الإجازة غير صالحة.',
            'statuses.*.in'      => 'قائمة الحالات تحتوي حالة غير صالحة.',
            'leave_type_id.*'    => 'نوع الإجازة غير صالح.',
            'leave_type_ids.*.*' => 'قائمة الأنواع تحتوي نوعاً غير صالح.',
            'year.*'             => 'السنة غير صحيحة.',
            'month.*'            => 'الشهر يجب أن يكون رقماً بين 1 و 12.',
            'date_from.date'     => 'تاريخ البداية غير صحيح.',
            'date_to.date'       => 'تاريخ النهاية غير صحيح.',
            'is_excuse.boolean'  => 'قيمة العذر غير صالحة.',
            'sort.in'            => 'حقل الترتيب المحدد غير مدعوم.',
        ];
    }
}
