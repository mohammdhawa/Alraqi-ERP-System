<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A guard files a duty exchange (work shifts piece 2, spec §6.1). The rules are ShiftSwapCheck's. */
class FileShiftSwapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'my_duty_date'      => ['required', 'date_format:Y-m-d'],
            'partner_id'        => ['required', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'partner_duty_date' => ['required', 'date_format:Y-m-d'],
            'reason'            => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['my_duty_date' => 'تاريخ مناوبتي', 'partner_id' => 'الزميل', 'partner_duty_date' => 'تاريخ مناوبة الزميل', 'reason' => 'السبب'];
    }
}
