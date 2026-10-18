<?php

declare(strict_types=1);

namespace App\Modules\Leave\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * GET /api/me/leave-balance — the caller's own, for a year (this one by
 * default). Legacy's `user_id` (an HR reading someone else's) is not here:
 * that is HR's list, scoped by its own permission.
 */
class MyLeaveBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'year.integer' => 'السنة يجب أن تكون رقماً صحيحاً.',
            'year.min'     => 'السنة غير صحيحة.',
            'year.max'     => 'السنة غير صحيحة.',
        ];
    }

    public function year(): int
    {
        return (int) $this->validated('year', Carbon::now()->year);
    }
}
