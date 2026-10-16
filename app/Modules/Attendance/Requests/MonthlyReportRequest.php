<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Requests;

use App\Modules\Attendance\Requests\Concerns\ReportsOnPeople;
use App\Modules\Attendance\Services\AttendanceReportService;
use App\Modules\Attendance\Support\RowSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * GET /api/attendance/report/monthly — a range (or `month` + `year`), at most
 * 92 days; the person filters; the two row filters; a sort.
 */
class MonthlyReportRequest extends FormRequest
{
    use ReportsOnPeople;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalisePeople();
        $this->expandMonth();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->rangeRules(),
            ...$this->peopleRules(),
            'has_absences'          => ['sometimes', 'in:0,1,true,false'],
            'has_missing_checkouts' => ['sometimes', 'in:0,1,true,false'],
            'sort'                  => ['sometimes', 'string', Rule::in(RowSort::values(AttendanceReportService::MONTHLY_SORTS))],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->capRange($validator));
    }

    public function flag(string $key): ?bool
    {
        return $this->has($key) ? filter_var($this->input($key), FILTER_VALIDATE_BOOLEAN) : null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->sharedMessages(),
            'has_absences.in'          => 'قيمة وجود غياب يجب أن تكون true أو false.',
            'has_missing_checkouts.in' => 'قيمة وجود انصراف مفقود يجب أن تكون true أو false.',
        ];
    }
}
