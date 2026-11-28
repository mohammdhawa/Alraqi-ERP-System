<?php

declare(strict_types=1);

namespace App\Modules\Documents\Requests;

use App\Modules\Documents\Requests\Concerns\ConfirmsTheCaller;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/documents/{document}/refuse — the employee refuses a document
 * sent to them (documents design §9.2, D17): a reason, trimmed, of 3 to 500
 * characters, which the sender is told, and the confirmation,
 * `ConfirmsTheCaller`'s. Whether the document still awaits the answer and the
 * password's match are the service's; the route's `can:answer,document` runs
 * first.
 */
class RefuseDocumentRequest extends FormRequest
{
    use ConfirmsTheCaller;

    private const REASON_MESSAGE = 'سبب الرفض مطلوب، من 3 إلى 500 حرف.';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ] + $this->confirmationRules();
    }

    public function messages(): array
    {
        return [
            'reason.required' => self::REASON_MESSAGE,
            'reason.string'   => self::REASON_MESSAGE,
            'reason.min'      => self::REASON_MESSAGE,
            'reason.max'      => self::REASON_MESSAGE,
        ] + $this->confirmationMessages();
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
