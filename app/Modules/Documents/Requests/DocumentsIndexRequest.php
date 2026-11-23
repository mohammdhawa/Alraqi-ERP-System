<?php

declare(strict_types=1);

namespace App\Modules\Documents\Requests;

use App\Modules\Documents\Enums\DocumentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/documents (documents design §7.7). Who may read the `all` box is
 * the controller's, so its refusal carries its own words.
 */
class DocumentsIndexRequest extends FormRequest
{
    public const BOXES = ['incoming', 'outgoing', 'all'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'box'    => ['required', 'string', Rule::in(self::BOXES)],
            'status' => ['sometimes', 'nullable', 'integer', Rule::enum(DocumentStatus::class)],
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'box.required'   => 'صندوق المستندات مطلوب.',
            'box.in'         => 'صندوق المستندات غير صالح.',
            'box.string'     => 'صندوق المستندات غير صالح.',
            'status.integer' => 'حالة المستند غير صالحة.',
            'status.enum'    => 'حالة المستند غير صالحة.',
            'search.string'  => 'نص البحث غير صالح.',
            'search.max'     => 'نص البحث لا يزيد على 200 حرف.',
        ];
    }

    public function box(): string
    {
        return (string) $this->validated('box');
    }

    public function status(): ?DocumentStatus
    {
        $status = $this->validated('status');

        return $status === null ? null : DocumentStatus::from((int) $status);
    }

    public function search(): ?string
    {
        $search = trim((string) $this->validated('search'));

        return $search === '' ? null : $search;
    }
}
