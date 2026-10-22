<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Requests;

use App\Modules\Attendance\Requests\Concerns\ReportsOnPeople;
use App\Modules\Attendance\Services\AttendanceReportService;
use App\Modules\Attendance\Support\RowSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/attendance/absent-today — a day (today by default), the person
 * filters, a sort, and the usual paging.
 */
class AbsentTodayRequest extends FormRequest
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
            'date'     => ['sometimes', 'date'],
            ...$this->peopleRules(),
            'sort'     => ['sometimes', 'string', Rule::in(RowSort::values(array_keys(AttendanceReportService::ABSENT_SORTS)))],
            'per_page' => ['sometimes', 'integer'],
            'page'     => ['sometimes', 'integer', 'min:1'],
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
