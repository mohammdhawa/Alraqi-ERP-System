<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Requests;

use App\Modules\Attendance\Requests\Concerns\ReportsOnPeople;
use App\Modules\Attendance\Services\AttendanceReportService;
use App\Modules\Attendance\Support\RowSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/attendance/report — one day (today by default), the person
 * filters, and a sort applied to every section.
 */
class DailyReportRequest extends FormRequest
{
    use ReportsOnPeople;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalisePeople();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'date'],
            ...$this->peopleRules(),
            'sort' => ['sometimes', 'string', Rule::in(RowSort::values(AttendanceReportService::DAILY_SORTS))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->sharedMessages();
    }
}
