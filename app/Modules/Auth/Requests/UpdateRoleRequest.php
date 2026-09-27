<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\UserRole;
use App\Modules\Auth\Services\RoleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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

    /**
     * GAP-16, the role-edit path: putting `auth.roles.assign` into a role that
     * is already granted over a unit or a managed scope would make every such
     * holder a scoped assigner — what AssignRoleRequest refuses to create by
     * granting. Grants of deleted accounts are ignored: they confer nothing,
     * and cannot be revoked through the API to clear the way.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()
                    || ! in_array(RoleService::GRANT_PERMISSION, (array) $this->validated('permissions', []), true)) {
                    return;
                }

                $role = $this->route('role');

                $heldNarrowly = $role instanceof Role && UserRole::query()
                    ->where('role_id', $role->getKey())
                    ->where('scope_type', '!=', ScopeType::Company->value)
                    ->whereHas('user')
                    ->exists();

                if ($heldNarrowly) {
                    $validator->errors()->add(
                        'permissions',
                        'لا تُضاف صلاحية إسناد الأدوار إلى دور مُسند على نطاق أضيق من «الشركة كاملة». اسحب تلك الإسنادات أو اجعلها على مستوى الشركة أولاً.',
                    );
                }
            },
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
