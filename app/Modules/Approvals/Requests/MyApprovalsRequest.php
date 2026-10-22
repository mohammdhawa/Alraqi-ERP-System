<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Requests;

use App\Modules\Approvals\Support\ApprovalFilters;
use App\Shared\Support\CsvList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/me/approvals — `status` picks the half of the inbox: `pending`
 * (the default: what waits on the caller now) or `decided` (what they
 * decided). Anything else is a 422 (C30), not a silent default.
 *
 * Legacy's queue filters (owner, 2026-10-20, parity audit C-4) through
 * ApprovalFilters, and a `sort`: `submitted_at` or `employee_name`, either
 * way (`-` for descending). Without one, newest first.
 */
class MyApprovalsRequest extends FormRequest
{
    public const PENDING = 'pending';

    public const DECIDED = 'decided';

    public const SORTS = ['submitted_at', '-submitted_at', 'employee_name', '-employee_name'];

    public function authorize(): bool
    {
        return true;
    }

    /** Id lists take the CSV every other list takes, or an array. */
    protected function prepareForValidation(): void
    {
        foreach (['department_ids', 'leave_type_ids'] as $key) {
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
            'status' => ['sometimes', 'string', 'in:'.self::PENDING.','.self::DECIDED],
            'sort'   => ['sometimes', 'string', Rule::in(self::SORTS)],
            ...ApprovalFilters::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.string' => 'قيمة الحالة غير صالحة.',
            'status.in'     => 'قيمة الحالة غير صالحة.',
            'sort.in'       => 'قيمة الترتيب غير صالحة.',
        ] + ApprovalFilters::messages();
    }

    /**
     * The validated filters, for ApprovalFilters.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return array_diff_key($this->validated(), array_flip(['status', 'sort', 'page', 'per_page']));
    }

    public function wantsDecided(): bool
    {
        return $this->validated('status', self::PENDING) === self::DECIDED;
    }
}
