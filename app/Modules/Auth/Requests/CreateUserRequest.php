<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * CreateUserRequest
 *
 * Validates a new-user payload. Email must be unique across the users table.
 * `is_active` is optional and defaults to true in the service. Route-level
 * access is guarded by permission:auth.users.create.
 *
 * NOTE: Role grants are NOT handled here. Use the dedicated
 * /api/auth/roles/assign endpoint to grant roles, keeping a single source of
 * truth for RBAC changes.
 */
class CreateUserRequest extends FormRequest
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
            'name'      => ['required', 'string', 'max:255'],
            'email'     => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password'  => ['required', 'string', 'min:8'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'يوجد مستخدم مسجّل بهذا البريد الإلكتروني بالفعل.',
        ];
    }
}
