<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * CreateRoleRequest
 *
 * Validates a new-role payload. Permissions are optional and given by name
 * (e.g. "hr.employees.view"); each must already exist in the catalogue.
 * Route-level access is guarded by permission:auth.roles.create.
 */
class CreateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name'          => ['required', 'string', 'max:255', Rule::unique('roles', 'name')],
            'description'   => ['nullable', 'string', 'max:255'],
            'permissions'   => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique'      => 'يوجد دور بهذا الاسم بالفعل.',
            'permissions.*.exists' => 'واحدة أو أكثر من الصلاحيات المحددة غير موجودة.',
        ];
    }
}
