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
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
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
