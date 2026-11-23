<?php

declare(strict_types=1);

namespace App\Modules\Documents\Requests;

use App\Modules\Documents\Models\DocumentTemplateCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST and PUT /api/documents/template-categories.
 */
class SaveTemplateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var DocumentTemplateCategory|null $current */
        $current = $this->route('category');

        return [
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('document_template_categories', 'name')->whereNull('deleted_at')->ignore($current?->id),
            ],
            'sort' => ['sometimes', 'nullable', 'integer', 'between:0,65535'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'اسم التصنيف مطلوب.',
            'name.string'   => 'اسم التصنيف مطلوب.',
            'name.max'      => 'اسم التصنيف لا يزيد على 100 حرف.',
            'name.unique'   => 'يوجد تصنيف بهذا الاسم.',
            'sort.integer'  => 'ترتيب التصنيف غير صالح.',
            'sort.between'  => 'ترتيب التصنيف غير صالح.',
        ];
    }

    /** @return array{name: string, sort?: int} */
    public function categoryData(): array
    {
        $data = ['name' => (string) $this->validated('name')];

        if ($this->validated('sort') !== null) {
            $data['sort'] = (int) $this->validated('sort');
        }

        return $data;
    }
}
