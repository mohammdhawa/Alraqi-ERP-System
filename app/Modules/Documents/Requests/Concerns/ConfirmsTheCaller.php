<?php

declare(strict_types=1);

namespace App\Modules\Documents\Requests\Concerns;

use App\Modules\Documents\Enums\Confirmation;
use Illuminate\Validation\Rule;

/**
 * How the caller confirmed who they are (documents design §10.3, D16), in
 * piece 2's rules and words: `confirmed_by` is 1 (biometric, as the app
 * reports) or 2 (the password, which is then required). For a manager's
 * decision and for the employee's answer alike — signing or refusing; each
 * request adds its own fields around these. The password's match is the
 * service's (`Hash::check`).
 */
trait ConfirmsTheCaller
{
    private const CONFIRMATION_MESSAGE = 'تأكيد الهوية مطلوب.';

    /**
     * @return array<string, mixed>
     */
    protected function confirmationRules(): array
    {
        return [
            'confirmed_by' => ['required', 'integer', Rule::enum(Confirmation::class)],
            'password'     => ['nullable', 'string', 'required_if:confirmed_by,'.Confirmation::Password->value],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function confirmationMessages(): array
    {
        return [
            'confirmed_by.required' => self::CONFIRMATION_MESSAGE,
            'confirmed_by.integer'  => self::CONFIRMATION_MESSAGE,
            'confirmed_by.enum'     => self::CONFIRMATION_MESSAGE,
            'password.required_if'  => 'كلمة المرور مطلوبة للتأكيد.',
            'password.string'       => 'كلمة المرور مطلوبة للتأكيد.',
        ];
    }

    public function confirmation(): Confirmation
    {
        return Confirmation::from((int) $this->validated('confirmed_by'));
    }

    public function password(): ?string
    {
        $password = $this->validated('password');

        return is_string($password) ? $password : null;
    }
}
