<?php

declare(strict_types=1);

namespace App\Modules\Documents\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/documents/{document}/close — the system administrator closes a
 * stuck document «لم يُستكمل» (documents design §7.8), with the reason its
 * creator is told. Who may is the route's `can:documents.close`.
 */
class CloseDocumentRequest extends FormRequest
{
    private const MESSAGE = 'سبب الإغلاق مطلوب، من 3 إلى 500 حرف.';

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

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }
}
