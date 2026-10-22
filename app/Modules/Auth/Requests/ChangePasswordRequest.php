<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ChangePasswordRequest
 *
 * AUTHORIZATION MODEL: SELF-SCOPED (C7). `authorize()` returns true and the
 * route carries no `permission:` middleware, because the action changes the
 * caller's own credential and nothing else. A user who may not change their own
 * password has no reason to hold a token — and requiring a permission would
 * make the forced first-login change ungrantable to the very people who need
 * it, which is every employee.
 *
 * WHY `current_password` IS REQUIRED even though the caller is authenticated:
 * a bearer token can be left behind on an unlocked phone. Re-proving the
 * credential is what stops a borrowed session from locking its owner out.
 *
 * WHY `different` MATTERS HERE MORE THAN USUAL: the first password is issued by
 * HR, so it is a credential somebody else has seen. "Changing" it to itself
 * would clear `must_change_password` while leaving that shared secret in place
 * — the rule is the difference between the flag meaning something and being
 * theatre.
 */
class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'string', 'min:8', 'max:128', 'confirmed', 'different:current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'كلمة المرور الحالية مطلوبة.',
            'password.required'         => 'كلمة المرور الجديدة مطلوبة.',
            'password.min'              => 'يجب ألا تقل كلمة المرور عن 8 أحرف.',
            'password.confirmed'        => 'تأكيد كلمة المرور غير مطابق.',
            'password.different'        => 'يجب أن تختلف كلمة المرور الجديدة عن الحالية.',
        ];
    }
}
