<?php

declare(strict_types=1);

namespace App\Modules\Auth\Requests;

use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\RoleService;
use App\Modules\Auth\Support\GrantScopeGuard;
use App\Modules\Auth\Support\PermissionReach;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Validator;

/**
 * AssignRoleRequest
 *
 * Validates a role-assignment payload: which user gets which role, over which
 * scope. Route-level access is guarded by permission:auth.roles.assign.
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
    private ?Role $roleModel = null;

    private bool $roleLoaded = false;

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

                $superAdminRole = $this->role()?->name === User::SUPER_ADMIN_ROLE;

                $violations = (new GrantScopeGuard)->violations(
                    ($this->scopeType() ?? ScopeType::Company)->value,
                    $this->scopeDepartmentId(),
                    $superAdminRole,
                );

                foreach ($violations as $violation) {
                    $validator->errors()->add($violation->field, $violation->message);
                }
            },
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || ! $this->refusesInertGrant()) {
                    return;
                }

                if ($this->grantWouldConferNothing()) {
                    // No "grant it company-wide instead": an assigner whose own
                    // grant is scoped gets a 403 for that. The fact, and the one
                    // remedy open to every assigner.
                    $validator->errors()->add(
                        'scope_type',
                        'لن يمنح هذا الإسناد أي صلاحية على هذا النطاق: كل صلاحيات هذا الدور لا تعمل إلا بإسناد على مستوى «الشركة كاملة». اختر دوراً آخر لهذا النطاق.',
                    );
                }
            },
        ];
    }

    // ⚙️ A scoped grant of a role carrying `auth.roles.assign` was refused
    // here from 2026-09-26 while GAP-16 was open: its holder was bounded in
    // WHERE they could grant, never in WHAT. RoleService::ensureMayGrant()
    // now bounds both — an assigner hands out only what they hold over the
    // same units — so a scoped assigner is a legitimate grant (2026-09-28).

    /**
     * A grant narrower than the company that would confer NOTHING (GAP-15).
     *
     * A scoped grant confers only the permissions PermissionReach honours; a
     * role made entirely of company-only permissions — `departments.update`,
     * `auth.users.*` — assigned over a unit is therefore a row that does
     * nothing. Accepting it with a 200 and listing it among the user's grants
     * tells the administrator they gave something they did not, so it is a 422
     * naming the scope, which is the field to change.
     *
     * Only WHOLLY inert grants. A role that is partly company-only still
     * confers its row-scoped part over the unit and is legitimate; the
     * dashboard warns about the rest. A role with no permissions at all is not
     * this case either — it confers nothing at any scope, company included, so
     * refusing it here would single out the scope for something the scope did
     * not cause.
     *
     * A request-level rule, not a model invariant (C4 does not apply): an inert
     * row is harmless, and this exists to stop an administrator misreading
     * what they did — which is an HTTP concern. Seeders may write one.
     */
    private function grantWouldConferNothing(): bool
    {
        if (($this->scopeType() ?? ScopeType::Company) === ScopeType::Company) {
            return false;
        }

        $permissions = $this->role()?->permissions->pluck('name')->all() ?? [];

        return $permissions !== [] && PermissionReach::effectiveForScopedGrant($permissions) === [];
    }

    /**
     * The role being granted, with its permissions, loaded ONCE for every
     * after-validation check (narrowed super_admin, inert grant).
     * Each used to fetch it for itself. RoleService loads it once more by id —
     * the service takes ids by design, so every caller gets the same lookup.
     */
    private function role(): ?Role
    {
        if (! $this->roleLoaded) {
            $this->roleModel = Role::with('permissions:id,name')->find($this->validated('role_id'));
            $this->roleLoaded = true;
        }

        return $this->roleModel;
    }

    /**
     * Granting refuses a scoped grant that would confer nothing. Revoking must
     * not: clearing such a row away is exactly what an administrator who made
     * one needs to do.
     */
    protected function refusesInertGrant(): bool
    {
        return true;
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
