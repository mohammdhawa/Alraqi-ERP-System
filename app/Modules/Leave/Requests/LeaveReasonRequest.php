<?php

declare(strict_types=1);

namespace App\Modules\Leave\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An HR act on a decided leave that may say why — undoing an excuse
 * (legacy's CancelLeaveExcuseRequest). The reason is OPTIONAL: HR is
 * correcting its own slip, and when it is given the employee is told it.
 */
class LeaveReasonRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.string' => 'السبب يجب أن يكون نصاً.',
            'reason.max'    => 'السبب طويل جداً.',
        ];
    }
}
