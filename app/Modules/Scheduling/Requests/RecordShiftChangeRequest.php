<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * HR records a person's shift on each date of a range (work shifts piece 2,
 * spec §5.1): at most 31 days, a shift or null for a day off, and a reason.
 * The rules on the rows themselves come back from the service as a 422.
 */
class RecordShiftChangeRequest extends FormRequest
{
    public const MAX_DAYS = 31;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from'     => ['required', 'date_format:Y-m-d'],
            'to'       => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'shift_id' => ['present', 'nullable', 'integer', Rule::exists('shifts', 'id')->whereNull('deleted_at')],
            'reason'   => ['required', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['from', 'to'])) {
                return;
            }
            $days = CarbonImmutable::parse((string) $this->input('from'))->diffInDays(CarbonImmutable::parse((string) $this->input('to'))) + 1;
            if ($days > self::MAX_DAYS) {
                $validator->errors()->add('to', 'لا يمكن أن تتجاوز الفترة 31 يوماً.');
            }
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['from' => 'من تاريخ', 'to' => 'إلى تاريخ', 'shift_id' => 'الوردية', 'reason' => 'سبب التعديل'];
    }
}
