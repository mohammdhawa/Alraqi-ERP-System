<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * EmployeeRequest
 *
 * Validates the payload for creating and updating an employee. A single
 * request class serves both store and update; `name` is required on create
 * (POST) and optional on update, while the remaining fields are always
 * optional and type-checked.
 *
 * AUTHORIZATION:
 * - Route-level access is enforced by the `permission:hr.employees.*`
 *   middleware. Field-level validation lives here.
 */
class EmployeeRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name'          => [$required, 'string', 'max:255'],
            'phone'         => ['nullable', 'string', 'max:50'],
            'email'         => ['nullable', 'email', 'max:255'],
            'address'       => ['nullable', 'string', 'max:1000'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'job_title'     => ['nullable', 'string', 'max:255'],
            'hire_date'     => ['nullable', 'date'],
            'salary'        => ['sometimes', 'numeric', 'min:0'],
            'status'        => ['sometimes', Rule::in(['active', 'inactive', 'terminated'])],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'      => 'The employee name is required.',
            'department_id.exists' => 'The selected department does not exist.',
            'status.in'          => 'Status must be one of: active, inactive, terminated.',
        ];
    }
}
