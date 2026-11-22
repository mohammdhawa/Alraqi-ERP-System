<?php

declare(strict_types=1);

namespace App\Modules\Documents\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DELETE /api/hr/employees/{employee}/signature — the system administrator's
 * deletion (D15), with the reason kept beside the deleted version.
 */
class DeleteSignatureRequest extends FormRequest
{
    private const MESSAGE = 'سبب الحذف مطلوب، من 3 إلى 500 حرف.';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => self::MESSAGE,
            'reason.string'   => self::MESSAGE,
            'reason.min'      => self::MESSAGE,
            'reason.max'      => self::MESSAGE,
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }
}
