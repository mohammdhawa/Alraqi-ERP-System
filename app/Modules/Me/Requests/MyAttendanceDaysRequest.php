<?php

declare(strict_types=1);

namespace App\Modules\Me\Requests;

use App\Modules\Attendance\Requests\Concerns\ReportsOnPeople;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * GET /api/me/attendance/days — the window of §14.3's grid and nothing else:
 * a range or `month` + `year`, at most 92 days, with the grid's rules and
 * messages.
 *
 * No person is read from the request: there is no `employee_id` rule, so one
 * sent is never in validated() (C7). The grid's day filters (`status`,
 * `working_days_only`, `sort`) are HR's; the calendar shows every day.
 */
class MyAttendanceDaysRequest extends FormRequest
{
    use ReportsOnPeople;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->expandMonth();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->rangeRules();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->capRange($validator));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->sharedMessages();
    }
}
