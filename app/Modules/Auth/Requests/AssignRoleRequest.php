<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Support\GrantScopeGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Validator;

/**
 * AssignRoleRequest
 *
 * Validates a role-assignment payload: which user gets which role, over which
 * scope. Route-level access is guarded by permission:auth.roles.update.
 *
 * SCOPE (GAP-3, decision 18) — both keys optional:
 *   scope_type           1 company · 2 unit · 3 managed (ScopeType). Omitted on
 *                        assign means company, the pre-GAP-3 meaning. Omitted
 *                        on unassign means every grant of the role.
 *   scope_department_id  required when scope_type is 2, and must be ABSENT
 *                        otherwise — the same rule `GrantScopeGuard` enforces
 *                        in the model for every other writer. `missing`
 *                        rather than `prohibited`, so `null` beside a company
 *                        scope is refused too instead of quietly accepted.
 *
 * super_admin is granted company-wide only (GrantScopeGuard); a narrowed one is
 * a field-level 422 on `scope_type` here, ahead of the model hook's exception.
 *
 * Unassign has its own request (UnassignRoleRequest). It differs only where
 * revoking has to accept what granting refuses.
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

            'scope_type'          => ['sometimes', 'integer', Rule::enum(ScopeType::class)],
            'scope_department_id' => $this->requiresDepartment()
                ? ['required', 'integer', $this->departmentExists()]
                : ['missing'],
        ];
    }

    /**
     * A new grant must name a live unit.
     */
    protected function departmentExists(): Exists
    {
        return Rule::exists('departments', 'id')->whereNull('deleted_at');
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || ! $this->refusesNarrowedSuperAdmin()) {
                    return;
                }

                $superAdminRole = Role::whereKey($this->validated('role_id'))->value('name') === User::SUPER_ADMIN_ROLE;

                $violations = (new GrantScopeGuard)->violations(
                    ($this->scopeType() ?? ScopeType::Company)->value,
                    $this->scopeDepartmentId(),
                    $superAdminRole,
                );

                foreach ($violations as $violation) {
                    $validator->errors()->add($violation->field, $violation->message);
                }
            },
        ];
    }

    /**
     * Granting refuses a narrowed super_admin. Revoking must not: it is how a
     * row written before that rule is cleared away.
     */
    protected function refusesNarrowedSuperAdmin(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return [
            'user_id.exists' => 'المستخدم المحدد غير موجود.',
            'role_id.exists' => 'الدور المحدد غير موجود.',

            'scope_type.enum'              => 'نطاق الصلاحية المحدد غير معرّف.',
            'scope_department_id.required' => 'الوحدة التنظيمية مطلوبة عندما يكون النطاق وحدة محددة.',
            'scope_department_id.exists'   => 'الوحدة التنظيمية المحددة غير موجودة أو تم حذفها.',
            'scope_department_id.missing'  => 'لا يُحدَّد وحدة تنظيمية إلا عندما يكون النطاق وحدة محددة.',
        ];
    }

    /**
     * The validated scope type, or null when the caller did not send one.
     */
    public function scopeType(): ?ScopeType
    {
        $value = $this->validated('scope_type');

        return $value === null ? null : ScopeType::from((int) $value);
    }

    public function scopeDepartmentId(): ?int
    {
        $value = $this->validated('scope_department_id');

        return $value === null ? null : (int) $value;
    }

    /**
     * Read from the RAW input, because the rules depend on it — validation has
     * not run yet. An undefined type answers false here and is refused by the
     * `scope_type` rule itself.
     */
    private function requiresDepartment(): bool
    {
        $raw = $this->input('scope_type');

        return is_numeric($raw) && ScopeType::tryFrom((int) $raw)?->requiresDepartment() === true;
    }
}
