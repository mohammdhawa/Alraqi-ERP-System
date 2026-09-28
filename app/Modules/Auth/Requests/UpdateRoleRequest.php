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
            'label'         => ['sometimes', 'nullable', 'string', 'max:255'],
            'description'   => ['nullable', 'string', 'max:255'],
            'permissions'   => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ];
    }

    // ⚙️ Adding `auth.roles.assign` to a role already held over a unit or a
    // managed scope was refused here from 2026-09-26 while GAP-16 was open.
    // RoleService now bounds what any assigner may hand out, so a scoped
    // assigner is legitimate, and what an EDITOR may add or remove is bounded
    // in RoleService::update() (2026-09-28).

    public function messages(): array
    {
        return [
            'name.unique'          => 'يوجد دور بهذا الاسم بالفعل.',
            'permissions.*.exists' => 'واحدة أو أكثر من الصلاحيات المحددة غير موجودة.',
        ];
    }
}
