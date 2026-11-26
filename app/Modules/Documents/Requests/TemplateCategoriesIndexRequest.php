<?php

declare(strict_types=1);

namespace App\Modules\Documents\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/documents/template-categories. `for_employees` is the template
 * list's own filter, applied to `templates_count` (ruling R19), so a picker
 * that sends it to both lists counts what it will list.
 */
class TemplateCategoriesIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'for_employees' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'for_employees.boolean' => 'نوع القالب غير صالح.',
        ];
    }

    public function forEmployees(): ?bool
    {
        return $this->validated('for_employees') === null ? null : $this->boolean('for_employees');
    }
}
