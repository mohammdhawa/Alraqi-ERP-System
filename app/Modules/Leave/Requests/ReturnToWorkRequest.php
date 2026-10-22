<?php

declare(strict_types=1);

namespace App\Modules\Leave\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/leave/requests/{leaveRequest}/return-to-work — legacy's
 * RecordReturnToWorkRequest. `returned_on` defaults to today in the
 * attendance timezone (someone walked in and needs to check in); given
 * explicitly, HR can record it the next morning. That it falls inside the
 * leave is the service's check, read from the locked row. The reason is
 * optional, and the employee is told it.
 */
class ReturnToWorkRequest extends FormRequest
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
            'returned_on' => ['sometimes', 'date'],
            'reason'      => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'returned_on.date' => 'تاريخ العودة غير صحيح.',
            'reason.string'    => 'السبب يجب أن يكون نصاً.',
            'reason.max'       => 'السبب طويل جداً.',
        ];
    }
}
