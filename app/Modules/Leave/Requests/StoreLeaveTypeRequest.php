<?php

declare(strict_types=1);

namespace App\Modules\Leave\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/leave/types — legacy's StoreLeaveTypeRequest, plus
 * `requires_attachment`.
 *
 * Names are unique as well as codes, legacy's argument: a picker and a
 * report identify a type by its name, so two with one name would be
 * indistinguishable over the balance policy each carries.
 */
class StoreLeaveTypeRequest extends FormRequest
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
            'code'                => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/', Rule::unique('leave_types', 'code')],
            'name_ar'             => ['required', 'string', 'max:100', Rule::unique('leave_types', 'name_ar')],
            'name_en'             => ['nullable', 'string', 'max:100', Rule::unique('leave_types', 'name_en')],
            'deducts_balance'     => ['required', 'boolean'],
            'for_requests'        => ['sometimes', 'boolean'],
            'for_excuses'         => ['sometimes', 'boolean'],
            'requires_reason'     => ['sometimes', 'boolean'],
            'requires_attachment' => ['sometimes', 'boolean'],
            'is_active'           => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return LeaveTypeMessages::all();
    }
}
