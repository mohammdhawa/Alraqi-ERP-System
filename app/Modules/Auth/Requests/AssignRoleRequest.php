<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * AssignRoleRequest
 *
 * Validates a role-assignment payload: which user gets which role. Both must
 * already exist. Route-level access is guarded by permission:auth.roles.update.
 */
class AssignRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    /**
     * Both exists rules carry ->whereNull('deleted_at') (C24). A bare exists
     * accepts a trashed row, which would grant a role to a deleted account or
     * grant a deleted role — writing a pivot row that grants nothing and
     * reporting success. These rules were correct only for as long as neither
     * table was soft-deletable; both now are.
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->whereNull('deleted_at')],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.exists' => 'المستخدم المحدد غير موجود.',
            'role_id.exists' => 'الدور المحدد غير موجود.',
        ];
    }
}
