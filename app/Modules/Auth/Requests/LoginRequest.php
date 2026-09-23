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
 * ⚙️ THE FIELD IS `login`, NOT `email` (account model, 2026-09-22).
 * It carries either an employee number or an email, and AuthService decides
 * which by looking, so there is deliberately NO `email` rule here: requiring an
 * email shape would reject `EMP-00042`, which is how almost everyone signs in.
 * The pair (employee number for staff, email for accounts with no employee)
 * is resolved in one grouped query — see AuthService::resolveByLogin().
 *
 * SECURITY NOTE:
 * - We validate format only (length, presence).
 * - We do NOT reveal whether an identifier exists via validation messages.
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
            'login'    => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
        ];
    }

    public function messages(): array
    {
        return [
            'login.required'    => 'رقم الموظف أو البريد الإلكتروني مطلوب.',
            'password.required' => 'كلمة المرور مطلوبة.',
            'password.min'      => 'يجب ألا تقل كلمة المرور عن 8 أحرف.',
        ];
    }
}
