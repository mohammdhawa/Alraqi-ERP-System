<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** A person's changes over a range of at most 92 days (work shifts piece 2, spec §5.1). */
class ShiftChangesIndexRequest extends FormRequest
{
    public const MAX_DAYS = 92;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to'   => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
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
                $validator->errors()->add('to', 'لا يمكن أن تتجاوز الفترة 92 يوماً.');
            }
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['from' => 'من تاريخ', 'to' => 'إلى تاريخ'];
    }
}
