<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Requests;

use App\Modules\Approvals\Enums\ApprovalStatus;
use App\Modules\Approvals\Support\ApprovalFilters;
use App\Shared\Support\CsvList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/approvals — HR's list of every approval in reach (owner,
 * 2026-10-20, parity audit C-4: legacy's admin saw the whole queue and
 * reassigned from it).
 *
 * Every status by default; `status` / `statuses` narrow it (1 pending ·
 * 2 approved · 3 rejected · 4 cancelled). The filters are the inbox's
 * (ApprovalFilters). `sort`: `submitted_at`, `decided_at` or
 * `employee_name`, `-` for descending; newest first without one.
 */
class ApprovalsIndexRequest extends FormRequest
{
    public const SORTS = ['submitted_at', '-submitted_at', 'decided_at', '-decided_at', 'employee_name', '-employee_name'];

    public function authorize(): bool
    {
        return true;
    }

    /** Id lists take the CSV every other list takes, or an array. */
    protected function prepareForValidation(): void
    {
        foreach (['statuses', 'department_ids', 'leave_type_ids'] as $key) {
            if ($this->has($key)) {
                $this->merge([$key => CsvList::parse($this->input($key))]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status'     => ['sometimes', 'integer', Rule::enum(ApprovalStatus::class)],
            'statuses'   => ['sometimes', 'array'],
            'statuses.*' => ['integer', Rule::enum(ApprovalStatus::class)],
            'sort'       => ['sometimes', 'string', Rule::in(self::SORTS)],
            ...ApprovalFilters::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.integer'     => 'قيمة الحالة غير صالحة.',
            'status.enum'        => 'قيمة الحالة غير صالحة.',
            'statuses.array'     => 'قائمة الحالات غير صالحة.',
            'statuses.*.integer' => 'قيمة الحالة غير صالحة.',
            'statuses.*.enum'    => 'قيمة الحالة غير صالحة.',
            'sort.in'            => 'قيمة الترتيب غير صالحة.',
        ] + ApprovalFilters::messages();
    }

    /**
     * @return array<int, int>
     */
    public function statuses(): array
    {
        return array_values(array_unique(array_map('intval', [
            ...($this->validated('statuses') ?? []),
            ...($this->has('status') ? [$this->validated('status')] : []),
        ])));
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return array_diff_key($this->validated(), array_flip(['status', 'statuses', 'sort', 'page', 'per_page']));
    }
}
