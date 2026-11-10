<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Requests;

use App\Modules\Scheduling\Enums\ShiftSwapStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** HR's list of duty exchanges (work shifts piece 2, spec §6.4). */
class ShiftSwapsIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status'      => ['nullable', 'integer', Rule::enum(ShiftSwapStatus::class)],
            'employee_id' => ['nullable', 'integer'],
            'from'        => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to'          => ['nullable', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from'],
            'per_page'    => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['from', 'to']) || ! $this->filled('from')) {
                return;
            }
            if (CarbonImmutable::parse((string) $this->input('from'))->diffInDays(CarbonImmutable::parse((string) $this->input('to'))) + 1 > 92) {
                $validator->errors()->add('to', 'لا يمكن أن تتجاوز الفترة 92 يوماً.');
            }
        }];
    }
}
