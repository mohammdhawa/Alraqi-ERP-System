<?php

declare(strict_types=1);

namespace App\Modules\Documents\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/documents/registers/{department}. Who may read a register, and
 * whether the unit has one, are the controller's, so each refusal has its
 * own words.
 */
class ExportRegisterIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'search.string' => 'نص البحث غير صالح.',
            'search.max'    => 'نص البحث لا يزيد على 200 حرف.',
        ];
    }

    public function search(): ?string
    {
        $search = trim((string) $this->validated('search'));

        return $search === '' ? null : $search;
    }
}
