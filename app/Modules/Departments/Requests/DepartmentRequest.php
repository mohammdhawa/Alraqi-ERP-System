<?php

declare(strict_types=1);

namespace App\Modules\Departments\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DepartmentRequest
 *
 * Validates the payload for creating and updating a department. A single
 * request class serves both store and update; the `sometimes` rule keeps
 * partial (PATCH-style) updates valid while still enforcing types.
 *
 * AUTHORIZATION:
 * - Route-level access is enforced by the `permission:departments.view`
 *   middleware (wired in routes.php, enforced once the RBAC package lands).
 *   This request therefore authorizes at the field level only.
 */
class DepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Coarse-grained access is handled by route middleware (auth:sanctum +
        // permission). Field-level validation lives here.
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        // On update (PUT/PATCH) `name` may be omitted; on create it is required.
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],

            // manager_id references an employee. The `exists:employees,id` rule
            // is intentionally deferred until the Employees module creates that
            // table — adding it now would validate against a missing table.
            'manager_id' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'The department name is required.',
            'name.max'      => 'The department name may not exceed 255 characters.',
        ];
    }
}
