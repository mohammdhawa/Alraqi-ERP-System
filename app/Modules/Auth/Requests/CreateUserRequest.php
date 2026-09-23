<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * CreateUserRequest
 *
 * Validates a new-user payload. A user account carries NO name of its own — its
 * display name is the linked employee's — so creating one REQUIRES an
 * `employee_id` (the front-door half of the "one login per employee" rule; the
 * DB's nullable-unique index is the backstop). The employee must exist and not
 * be soft-deleted, and must not already have an account. Email must be unique.
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
            'employee_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')->whereNull('deleted_at'),
                Rule::unique('users', 'employee_id'),
            ],
            // ⚙️ OPTIONAL SINCE THE ACCOUNT MODEL (2026-09-22). It was required,
            // which made an account impossible for the majority of staff — most
            // site workers have no email address. The login identifier is the
            // employee number, and `employee_id` above is required, so every
            // account created here can sign in whether or not this is supplied.
            // Still unique when present: two accounts sharing a contact address
            // is a data error whatever the address is for.
            'email'     => ['sometimes', 'nullable', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password'  => ['required', 'string', 'min:8'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'employee_id.required' => 'يجب ربط الحساب بموظف.',
            'employee_id.exists'   => 'الموظف المحدد غير موجود.',
            'employee_id.unique'   => 'هذا الموظف مرتبط بحساب مستخدم بالفعل.',
            'email.unique'         => 'يوجد مستخدم مسجّل بهذا البريد الإلكتروني بالفعل.',
        ];
    }
}
