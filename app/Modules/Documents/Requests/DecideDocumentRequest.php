<?php

declare(strict_types=1);

namespace App\Modules\Documents\Requests;

use App\Modules\Approvals\Enums\Decision;
use App\Modules\Documents\Requests\Concerns\ConfirmsTheCaller;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/documents/{document}/decision (documents design §7.2, §10.3). The
 * note is optional on both decisions, as legacy. The confirmation is
 * `ConfirmsTheCaller`'s. Whose turn it is, and the password's match, are the
 * service's: the route's `can:view,document` runs first, so an outsider's
 * body is never read.
 */
class DecideDocumentRequest extends FormRequest
{
    use ConfirmsTheCaller;

    private const DECISION_MESSAGE = 'القرار يجب أن يكون موافقة أو رفضاً.';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision'     => ['required', 'string', Rule::in(['approve', 'reject'])],
            'note'         => ['sometimes', 'nullable', 'string', 'max:500'],
        ] + $this->confirmationRules();
    }

    public function messages(): array
    {
        return [
            'decision.required'     => self::DECISION_MESSAGE,
            'decision.string'       => self::DECISION_MESSAGE,
            'decision.in'           => self::DECISION_MESSAGE,
            'note.string'           => 'الملاحظة يجب أن تكون نصاً.',
            'note.max'              => 'الملاحظة لا تزيد على 500 حرف.',
        ] + $this->confirmationMessages();
    }

    public function decision(): Decision
    {
        return $this->validated('decision') === 'reject' ? Decision::Reject : Decision::Approve;
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return is_string($note) ? $note : null;
    }
}
