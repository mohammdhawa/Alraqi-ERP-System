<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/approvals/{approval}/steps/{step}/approve and /reject. The note
 * is optional on both, for every consumer (§9 Q6 = A). Who may decide is the
 * engine's "whose turn" rule, after the route's `can:view` — which runs
 * first, so an outsider's body is never read.
 */
class DecideStepRequest extends FormRequest
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
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.string' => 'الملاحظة يجب أن تكون نصاً.',
            'note.max'    => 'الملاحظة طويلة جداً.',
        ];
    }
}
