<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Auth\Exceptions\GrantScopeException;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Models\UserRole;
use App\Modules\Auth\Support\PermissionCache;
use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GAP-3 — the scope on a role grant (START_HERE §9, decision 18).
 *
 * What a grant IS changed: role + scope, not role alone. These tests pin the
 * write side — what `/roles/assign` and `/roles/unassign` accept and store, and
 * that the model refuses a malformed scope from writers that never pass through
 * a FormRequest. EmployeeScopeTest covers what a scope then lets a user see.
 */
class RoleScopeTest extends TestCase
{
    use RefreshDatabase;

    private Department $division;

    private Department $section;

    private Department $otherSection;

    private Role $role;

    private User $target;

    protected function setUp(): void
    {
        parent::setUp();

        $level = static fn (int $index): int => DepartmentLevel::cases()[$index]->value;

        $root = Department::create(['name' => 'الإدارة العامة', 'code' => 'GM', 'level' => $level(0), 'parent_id' => null]);
        $this->division = Department::create(['name' => 'إدارة', 'code' => 'D', 'level' => $level(1), 'parent_id' => $root->id]);
        $this->section = Department::create(['name' => 'قسم 1', 'code' => 'S1', 'level' => $level(2), 'parent_id' => $this->division->id]);
        $this->otherSection = Department::create(['name' => 'قسم 2', 'code' => 'S2', 'level' => $level(2), 'parent_id' => $this->division->id]);

        $this->role = Role::create(['name' => 'supervisor']);
        $this->target = User::factory()->create();
    }

    public function test_a_grant_with_no_scope_is_company_wide_as_before(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/auth/roles/assign', ['user_id' => $this->target->id, 'role_id' => $this->role->id])
            ->assertOk()
            ->assertJsonPath('data.grants.0.role', 'supervisor')
            ->assertJsonPath('data.grants.0.scope_type', ScopeType::Company->value)
            ->assertJsonPath('data.grants.0.department', null);
    }

    public function test_a_unit_grant_is_stored_and_rendered_with_its_unit(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/auth/roles/assign', [
            'user_id'             => $this->target->id,
            'role_id'             => $this->role->id,
            'scope_type'          => ScopeType::Unit->value,
            'scope_department_id' => $this->section->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.grants.0.scope_type', ScopeType::Unit->value)
            ->assertJsonPath('data.grants.0.scope_label', 'وحدة محددة')
            ->assertJsonPath('data.grants.0.department.id', $this->section->id);

        $this->assertDatabaseHas('audit_logs', ['event' => 'role_assigned']);
    }

    public function test_the_same_role_over_two_units_is_two_grants(): void
    {
        // The old primary(user_id, role_id) forbade this — a supervisor over
        // two sections — which is why the key was replaced.
        $this->actingAsAdmin();

        foreach ([$this->section, $this->otherSection] as $unit) {
            $response = $this->postJson('/api/auth/roles/assign', [
                'user_id'             => $this->target->id,
                'role_id'             => $this->role->id,
                'scope_type'          => ScopeType::Unit->value,
                'scope_department_id' => $unit->id,
            ])->assertOk();
        }

        $this->assertSame(2, UserRole::where('user_id', $this->target->id)->count());

        // Two grants, but the name list says the role once.
        $response
            ->assertJsonCount(2, 'data.grants')
            ->assertJsonPath('data.roles', ['supervisor']);
    }

    public function test_assigning_the_same_grant_twice_writes_one_row(): void
    {
        // The unique index cannot catch this one on its own — NULL is never
        // equal to NULL — so the service has to.
        $this->actingAsAdmin();

        $payload = ['user_id' => $this->target->id, 'role_id' => $this->role->id, 'scope_type' => ScopeType::Company->value];

        $this->postJson('/api/auth/roles/assign', $payload)->assertOk();
        $this->postJson('/api/auth/roles/assign', $payload)->assertOk();

        $this->assertSame(1, UserRole::where('user_id', $this->target->id)->count());
    }

    public function test_the_scope_shape_is_validated_field_by_field(): void
    {
        $this->actingAsAdmin();
        $base = ['user_id' => $this->target->id, 'role_id' => $this->role->id];

        $this->postJson('/api/auth/roles/assign', $base + ['scope_type' => ScopeType::Unit->value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scope_department_id');

        $this->postJson('/api/auth/roles/assign', $base + [
            'scope_type'          => ScopeType::Company->value,
            'scope_department_id' => $this->section->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('scope_department_id');

        // `missing`, not `prohibited`: an explicit null beside a non-unit scope
        // is refused too, rather than accepted as if it meant nothing.
        $this->postJson('/api/auth/roles/assign', $base + [
            'scope_type'          => ScopeType::Managed->value,
            'scope_department_id' => null,
        ])->assertUnprocessable()->assertJsonValidationErrors('scope_department_id');

        $this->postJson('/api/auth/roles/assign', $base + ['scope_type' => 9])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scope_type');

        $this->otherSection->delete();
        $this->postJson('/api/auth/roles/assign', $base + [
            'scope_type'          => ScopeType::Unit->value,
            'scope_department_id' => $this->otherSection->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('scope_department_id');

        $this->assertSame(0, UserRole::where('user_id', $this->target->id)->count());
    }

    public function test_the_model_refuses_a_malformed_scope_from_any_writer(): void
    {
        // A seeder or the importer writes around AssignRoleRequest. The saving
        // hook is what stops them — through attach() as well, because
        // User::roles() saves through the UserRole pivot class.
        $this->expectException(GrantScopeException::class);

        $this->target->roles()->attach($this->role->id, ['scope_type' => ScopeType::Unit->value]);
    }

    public function test_the_inverse_relation_crosses_the_same_guard(): void
    {
        // Found in review: Role::users() was a plain belongsToMany, so an
        // attach from the role's side inserted the pivot row raw and skipped
        // the saving hook entirely.
        $this->expectException(GrantScopeException::class);

        $this->role->users()->attach($this->target->id, ['scope_type' => ScopeType::Unit->value]);
    }

    public function test_the_model_refuses_a_unit_on_a_company_grant(): void
    {
        $this->expectException(GrantScopeException::class);

        UserRole::create([
            'user_id'             => $this->target->id,
            'role_id'             => $this->role->id,
            'scope_type'          => ScopeType::Company->value,
            'scope_department_id' => $this->section->id,
        ]);
    }

    public function test_unassign_with_a_scope_removes_only_that_grant(): void
    {
        $this->actingAsAdmin();

        foreach ([$this->section, $this->otherSection] as $unit) {
            $this->target->roles()->attach($this->role->id, ['scope_type' => ScopeType::Unit->value, 'scope_department_id' => $unit->id]);
        }

        $this->postJson('/api/auth/roles/unassign', [
            'user_id'             => $this->target->id,
            'role_id'             => $this->role->id,
            'scope_type'          => ScopeType::Unit->value,
            'scope_department_id' => $this->section->id,
        ])
            ->assertOk()
            ->assertJsonCount(1, 'data.grants')
            ->assertJsonPath('data.grants.0.department.id', $this->otherSection->id);
    }

    public function test_unassign_without_a_scope_removes_every_grant_of_the_role(): void
    {
        $this->actingAsAdmin();

        $this->target->roles()->attach($this->role->id);
        $this->target->roles()->attach($this->role->id, ['scope_type' => ScopeType::Unit->value, 'scope_department_id' => $this->section->id]);

        $this->postJson('/api/auth/roles/unassign', ['user_id' => $this->target->id, 'role_id' => $this->role->id])
            ->assertOk()
            ->assertJsonCount(0, 'data.grants');

        $this->assertSame(0, UserRole::where('user_id', $this->target->id)->count());
    }

    // ── super_admin has no scope ─────────────────────────────────────────

    public function test_super_admin_cannot_be_granted_over_a_unit_or_managed_units(): void
    {
        $this->actingAsAdmin();
        $superAdmin = Role::where('name', User::SUPER_ADMIN_ROLE)->value('id');

        $this->postJson('/api/auth/roles/assign', [
            'user_id'             => $this->target->id,
            'role_id'             => $superAdmin,
            'scope_type'          => ScopeType::Unit->value,
            'scope_department_id' => $this->section->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['scope_type']);

        $this->postJson('/api/auth/roles/assign', [
            'user_id'    => $this->target->id,
            'role_id'    => $superAdmin,
            'scope_type' => ScopeType::Managed->value,
        ])->assertUnprocessable()->assertJsonValidationErrors(['scope_type']);

        $this->assertFalse($this->target->fresh()->isSuperAdmin());

        // The model refuses it from writers that skip the FormRequest, too.
        $this->expectException(GrantScopeException::class);
        $this->target->roles()->attach($superAdmin, ['scope_type' => ScopeType::Managed->value]);
    }

    public function test_a_narrowed_super_admin_row_written_around_the_guard_is_no_bypass(): void
    {
        $this->seedRbac();

        DB::table('user_roles')->insert([
            'user_id'             => $this->target->id,
            'role_id'             => Role::where('name', User::SUPER_ADMIN_ROLE)->value('id'),
            'scope_type'          => ScopeType::Unit->value,
            'scope_department_id' => $this->section->id,
        ]);
        PermissionCache::flush();

        $this->assertFalse($this->target->isSuperAdmin());
        $this->assertFalse($this->target->hasPermission('hr.employees.view'));
    }

    // ── A role administrator grants only within their own reach ──────────

    public function test_a_unit_scoped_role_admin_cannot_grant_company_wide_even_to_themselves(): void
    {
        $admin = $this->actingAsScopedUser(['auth.roles.assign'], ScopeType::Unit, $this->division->id);

        foreach ([$this->target, $admin] as $grantee) {
            $this->postJson('/api/auth/roles/assign', ['user_id' => $grantee->id, 'role_id' => $this->role->id])
                ->assertForbidden()
                ->assertJsonPath('success', false);
        }

        $this->assertSame(0, UserRole::where('role_id', $this->role->id)->count());
    }

    public function test_a_unit_scoped_role_admin_grants_inside_their_unit_but_not_above_it(): void
    {
        $this->actingAsScopedUser(['auth.roles.assign'], ScopeType::Unit, $this->division->id);

        $this->postJson('/api/auth/roles/assign', [
            'user_id'             => $this->target->id,
            'role_id'             => $this->role->id,
            'scope_type'          => ScopeType::Unit->value,
            'scope_department_id' => $this->section->id,
        ])->assertOk();

        $this->postJson('/api/auth/roles/assign', [
            'user_id'             => $this->target->id,
            'role_id'             => $this->role->id,
            'scope_type'          => ScopeType::Unit->value,
            'scope_department_id' => $this->division->parent_id,
        ])->assertForbidden();

        $this->assertSame(1, UserRole::where('user_id', $this->target->id)->count());
    }

    public function test_a_managed_grant_must_not_reach_past_the_role_admin(): void
    {
        $this->actingAsScopedUser(['auth.roles.assign'], ScopeType::Unit, $this->division->id);

        $head = Employee::factory()->create(['department_id' => $this->division->parent_id]);
        Department::whereKey($this->division->parent_id)->update(['manager_id' => $head->id]);
        $this->target->update(['employee_id' => $head->id]);

        $this->postJson('/api/auth/roles/assign', [
            'user_id'    => $this->target->id,
            'role_id'    => $this->role->id,
            'scope_type' => ScopeType::Managed->value,
        ])->assertForbidden();

        // Managing a unit inside the admin's reach is fine.
        Department::whereKey($this->division->parent_id)->update(['manager_id' => null]);
        $this->section->update(['manager_id' => $head->id]);

        $this->postJson('/api/auth/roles/assign', [
            'user_id'    => $this->target->id,
            'role_id'    => $this->role->id,
            'scope_type' => ScopeType::Managed->value,
        ])->assertOk();
    }

    /**
     * Found in review, 2026-09-26: the managed rule checks every unit the
     * grantee manages TODAY, and for someone who manages nothing that is no
     * units — so the loop ran zero times and the grant passed unchecked. Its
     * reach would then be set by whatever unit they are appointed to next,
     * however far outside the admin's own.
     */
    public function test_a_scoped_role_admin_cannot_give_managed_scope_to_someone_who_manages_nothing(): void
    {
        $this->actingAsScopedUser(['auth.roles.assign'], ScopeType::Unit, $this->division->id);

        $nobody = Employee::factory()->create(['department_id' => $this->section->id]);
        $this->target->update(['employee_id' => $nobody->id]);

        $this->postJson('/api/auth/roles/assign', [
            'user_id'    => $this->target->id,
            'role_id'    => $this->role->id,
            'scope_type' => ScopeType::Managed->value,
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'نطاق «الوحدات التي أديرها» لمستخدم لا يدير حالياً أي وحدة لا يمنحه ولا يسحبه إلا من يملك صلاحية الإسناد على مستوى الشركة كاملة.');

        $this->assertSame(0, UserRole::where('user_id', $this->target->id)->count());
    }

    public function test_nor_strip_one_but_a_company_wide_admin_can_do_both(): void
    {
        $nobody = Employee::factory()->create(['department_id' => $this->section->id]);
        $this->target->update(['employee_id' => $nobody->id]);

        // A company-wide assigner is unbounded, so the latent grant is theirs
        // to make — an appointment is expected to follow.
        $this->actingAsScopedUser(['auth.roles.assign'], ScopeType::Company);
        $body = [
            'user_id'    => $this->target->id,
            'role_id'    => $this->role->id,
            'scope_type' => ScopeType::Managed->value,
        ];
        $this->postJson('/api/auth/roles/assign', $body)->assertOk();

        // Symmetric, like every reach rule: a scoped admin cannot strip it.
        $this->actingAsScopedUser(['auth.roles.assign'], ScopeType::Unit, $this->division->id);
        $this->postJson('/api/auth/roles/unassign', $body)->assertForbidden();

        $this->assertSame(1, UserRole::where('user_id', $this->target->id)->count());
    }

    public function test_a_unit_scoped_role_admin_cannot_revoke_a_grant_beyond_their_reach(): void
    {
        $this->actingAsScopedUser(['auth.roles.assign'], ScopeType::Unit, $this->division->id);

        $this->target->roles()->attach($this->role->id);
        $this->target->roles()->attach($this->role->id, ['scope_type' => ScopeType::Unit->value, 'scope_department_id' => $this->section->id]);

        // "Every grant of the role" includes the company-wide one: nothing goes.
        $this->postJson('/api/auth/roles/unassign', ['user_id' => $this->target->id, 'role_id' => $this->role->id])
            ->assertForbidden();
        $this->assertSame(2, UserRole::where('user_id', $this->target->id)->count());

        $this->postJson('/api/auth/roles/unassign', [
            'user_id'             => $this->target->id,
            'role_id'             => $this->role->id,
            'scope_type'          => ScopeType::Unit->value,
            'scope_department_id' => $this->section->id,
        ])->assertOk()->assertJsonCount(1, 'data.grants');
    }

    // ── Revoking accepts what granting refuses ───────────────────────────

    public function test_a_grant_on_a_deleted_unit_can_still_be_revoked_on_its_own(): void
    {
        $this->actingAsAdmin();

        foreach ([$this->section, $this->otherSection] as $unit) {
            $this->target->roles()->attach($this->role->id, ['scope_type' => ScopeType::Unit->value, 'scope_department_id' => $unit->id]);
        }
        $this->section->delete();

        $this->postJson('/api/auth/roles/unassign', [
            'user_id'             => $this->target->id,
            'role_id'             => $this->role->id,
            'scope_type'          => ScopeType::Unit->value,
            'scope_department_id' => $this->section->id,
        ])
            ->assertOk()
            ->assertJsonCount(1, 'data.grants')
            ->assertJsonPath('data.grants.0.department.id', $this->otherSection->id);

        // A NEW grant into the deleted unit is still refused.
        $this->postJson('/api/auth/roles/assign', [
            'user_id'             => $this->target->id,
            'role_id'             => $this->role->id,
            'scope_type'          => ScopeType::Unit->value,
            'scope_department_id' => $this->section->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['scope_department_id']);
    }

    // ── A malformed stored row ───────────────────────────────────────────

    public function test_an_undefined_stored_scope_type_grants_nothing_instead_of_failing(): void
    {
        $this->seedRbac();
        $this->role->permissions()->sync(Permission::whereIn('name', ['hr.employees.view', 'hr.employees.update'])->pluck('id'));

        $viewer = Role::create(['name' => 'viewer']);
        $viewer->permissions()->sync(Permission::where('name', 'hr.employees.view')->pluck('id'));
        $this->target->roles()->attach($viewer->id);

        DB::table('user_roles')->insert(['user_id' => $this->target->id, 'role_id' => $this->role->id, 'scope_type' => 9]);
        PermissionCache::flush();

        $this->assertTrue($this->target->hasPermission('hr.employees.view'));
        $this->assertFalse($this->target->hasPermission('hr.employees.update'));

        $this->actingAsAdmin();
        $this->getJson('/api/auth/users')->assertOk();
    }

    // ── Role names are listed once ───────────────────────────────────────

    public function test_the_session_payload_names_a_role_held_over_two_units_once(): void
    {
        $user = $this->actingAsScopedUser(['hr.employees.view'], ScopeType::Unit, $this->section->id);
        $roleId = $user->roles()->value('roles.id');
        $user->roles()->attach($roleId, ['scope_type' => ScopeType::Unit->value, 'scope_department_id' => $this->otherSection->id]);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonCount(1, 'data.roles');
    }
}
