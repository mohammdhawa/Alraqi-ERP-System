<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/me/approvals — `status` picks the half of the inbox: `pending`
 * (the default: what waits on the caller now) or `decided` (what they
 * decided). Anything else is a 422 (C30), not a silent default.
 */
class MyApprovalsRequest extends FormRequest
{
    public const PENDING = 'pending';

    public const DECIDED = 'decided';

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
            'status' => ['sometimes', 'string', 'in:'.self::PENDING.','.self::DECIDED],
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
        ];
    }

    public function wantsDecided(): bool
    {
        return $this->validated('status', self::PENDING) === self::DECIDED;
    }
}
