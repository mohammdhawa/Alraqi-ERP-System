<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateUserRequest
 *
 * Validates a user-edit payload. All fields are optional (partial update):
 * - employee_id: if present, re-links the account to a different employee. The
 *   employee must exist, not be soft-deleted, and not already own another
 *   account (unique, ignoring this user). There is no `name` to edit — a user's
 *   name is the linked employee's.
 * - email: if present, must stay unique (ignoring this user itself).
 * - password: if present and non-empty, it is re-hashed by the service. A
 *   nullable value lets a client send the field without changing the password.
 *
 * Route-level access is guarded by permission:auth.users.update.
 */
class UpdateUserRequest extends FormRequest
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
            'employee_id' => [
                'sometimes',
                'integer',
                Rule::exists('employees', 'id')->whereNull('deleted_at'),
                Rule::unique('users', 'employee_id')->ignore($this->route('user')),
            ],
            'email'     => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'password'  => ['sometimes', 'nullable', 'string', 'min:8'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'employee_id.exists' => 'الموظف المحدد غير موجود.',
            'employee_id.unique' => 'هذا الموظف مرتبط بحساب مستخدم بالفعل.',
            'email.unique'       => 'يوجد مستخدم مسجّل بهذا البريد الإلكتروني بالفعل.',
        ];
    }
}
