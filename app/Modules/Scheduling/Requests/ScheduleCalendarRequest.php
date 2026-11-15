<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Requests;

use App\Modules\HR\Requests\Concerns\FiltersPeople;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** A range of at most 92 days, as the attendance reports (spec §9.2). */
class ScheduleCalendarRequest extends FormRequest
{
    use FiltersPeople;

    public const MAX_RANGE_DAYS = 92;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalisePeople();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from'     => ['required', 'date_format:Y-m-d'],
            'to'       => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ] + $this->peopleRules();
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
