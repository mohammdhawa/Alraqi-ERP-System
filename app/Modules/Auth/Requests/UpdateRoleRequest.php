<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateRoleRequest
 *
 * Validates a role-edit payload. All fields are optional (partial update):
 * - name: if present, must stay unique (ignoring this role itself).
 * - permissions: if present, REPLACES the role's permission set (sync); each
 *   entry must be an existing permission name.
 *
 * Route-level access is guarded by permission:auth.roles.update.
 */
class UpdateRoleRequest extends FormRequest
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
            'name'          => ['sometimes', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($this->route('role'))],
            'description'   => ['nullable', 'string', 'max:255'],
            'permissions'   => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique'          => 'يوجد دور بهذا الاسم بالفعل.',
            'permissions.*.exists' => 'واحدة أو أكثر من الصلاحيات المحددة غير موجودة.',
        ];
    }
}
