<?php

declare(strict_types=1);

namespace App\Modules\Documents\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/documents/{document}/comments (documents design §7.5). The body is
 * trimmed first, so a blank one is a missing one. Who may comment is the
 * route's `can:view,document`, which runs before this body is read.
 */
class DocumentCommentRequest extends FormRequest
{
    private const REQUIRED_MESSAGE = 'نص التعليق مطلوب.';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $body = $this->input('body');

        if (is_string($body)) {
            $this->merge(['body' => trim($body)]);
        }
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => self::REQUIRED_MESSAGE,
            'body.string'   => self::REQUIRED_MESSAGE,
            'body.max'      => 'التعليق لا يزيد على 2000 حرف.',
        ];
    }

    public function body(): string
    {
        return (string) $this->validated('body');
    }
}
