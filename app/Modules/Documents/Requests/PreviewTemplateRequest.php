<?php

declare(strict_types=1);

namespace App\Modules\Documents\Requests;

use Illuminate\Validation\Rule;

/**
 * POST /api/documents/templates/preview (documents design §8.2): the body of
 * a save, checked the same way (the page sanitised, its chips known), but
 * nothing is saved. So the name need not be unique (an edit previews under
 * its own name) and the category is optional (ruling R3).
 */
final class PreviewTemplateRequest extends SaveTemplateRequest
{
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:150'];
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'category_id' => ['nullable', 'integer', Rule::exists('document_template_categories', 'id')->whereNull('deleted_at')],
        ];
    }
}
