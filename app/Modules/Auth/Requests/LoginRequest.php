<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * LoginRequest
 *
 * Validates login credentials before they reach the controller.
 *
 * WHY Form Requests:
 * - Validation logic is separated from controller logic.
 * - Laravel auto-returns 422 with structured errors if validation fails.
 * - Reusable across controllers if needed.
 * - Testable in isolation.
 *
 * SECURITY NOTE:
 * - We validate format only (email shape, password length).
 * - We do NOT reveal whether an email exists via validation messages.
 * - The actual credential check happens in AuthService.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Public endpoint
    }

    public function rules(): array
    {
        return [
            'email'    => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required'    => 'البريد الإلكتروني مطلوب.',
            'email.email'       => 'يُرجى إدخال بريد إلكتروني صحيح.',
            'password.required' => 'كلمة المرور مطلوبة.',
            'password.min'      => 'يجب ألا تقل كلمة المرور عن 8 أحرف.',
        ];
    }
}