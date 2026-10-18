<?php

declare(strict_types=1);

namespace App\Modules\Leave\Requests;

use App\Modules\Attendance\Requests\Concerns\ReportsOnPeople;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * GET /api/leave/balances — legacy's LeaveBalancesIndexRequest: a year (this
 * one by default) and the same person filters attendance's lists take —
 * employees, units with their subtree, a name part or staff number.
 */
class LeaveBalancesRequest extends FormRequest
{
    use ReportsOnPeople;

    public const SORTS = ['employee_name', 'employee_number'];

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
        $sorts = [...self::SORTS, ...array_map(static fn (string $key): string => '-'.$key, self::SORTS)];

        return $this->peopleRules() + [
            'year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
            'sort' => ['sometimes', 'string', 'in:'.implode(',', $sorts)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->sharedMessages();
    }

    public function year(): int
    {
        return (int) $this->validated('year', Carbon::now()->year);
    }
}
