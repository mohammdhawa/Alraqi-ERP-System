<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** A range of at most 92 days, as the attendance reports (spec §9.2). */
class ScheduleCalendarRequest extends FormRequest
{
    public const MAX_RANGE_DAYS = 92;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from'          => ['required', 'date_format:Y-m-d'],
            'to'            => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'department_id' => ['sometimes', 'integer', Rule::exists('departments', 'id')],
            'employee_id'   => ['sometimes', 'integer', Rule::exists('employees', 'id')],
            'per_page'      => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $days = CarbonImmutable::parse($this->input('from'))->diffInDays(CarbonImmutable::parse($this->input('to'))) + 1;
            if ($days > self::MAX_RANGE_DAYS) {
                $validator->errors()->add('to', 'لا يمكن أن تتجاوز الفترة 92 يوماً.');
            }
        }];
    }
}
