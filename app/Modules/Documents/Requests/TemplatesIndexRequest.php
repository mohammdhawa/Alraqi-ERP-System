<?php

declare(strict_types=1);

namespace App\Modules\Documents\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/documents/templates. `status` is read only for a template
 * manager; everyone else sees active templates.
 */
class TemplatesIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id'   => ['sometimes', 'nullable', 'integer'],
            'search'        => ['sometimes', 'nullable', 'string', 'max:200'],
            'for_employees' => ['sometimes', 'nullable', 'boolean'],
            'status'        => ['sometimes', 'nullable', 'string', Rule::in(['active', 'inactive', 'all'])],
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.integer'   => 'التصنيف المختار غير صالح.',
            'search.string'         => 'نص البحث غير صالح.',
            'search.max'            => 'نص البحث لا يزيد على 200 حرف.',
            'for_employees.boolean' => 'نوع القالب غير صالح.',
            'status.in'             => 'حالة القالب غير صالحة.',
            'status.string'         => 'حالة القالب غير صالحة.',
        ];
    }

    public function categoryId(): ?int
    {
        $id = $this->validated('category_id');

        return $id === null ? null : (int) $id;
    }

    public function search(): ?string
    {
        $search = trim((string) $this->validated('search'));

        return $search === '' ? null : $search;
    }

    public function forEmployees(): ?bool
    {
        return $this->validated('for_employees') === null ? null : $this->boolean('for_employees');
    }

    public function status(): string
    {
        return (string) ($this->validated('status') ?? 'all');
    }
}
