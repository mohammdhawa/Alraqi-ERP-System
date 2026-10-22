<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Models\UserRole;
use App\Modules\Auth\Support\PermissionCache;
use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GAP-16 — an administrator may make someone gain or lose only permissions
 * they hold themselves, over the same units (START_HERE §9, decision 24).
 *
 * Before this, assigning was bounded in WHERE (the assigner's own reach) and
 * never in WHAT, so a clerk holding auth.roles.assign over a unit could hand
 * themselves that unit's payroll. The project owner ruled, 2026-09-28:
 *
 *   - the subset rule binds EVERY assigner but a super admin, company-wide
 *     ones included;
 *   - super_admin is granted and revoked by a super admin only;
 *   - revoking is bounded exactly as granting is.
 *
 * Editing a role's definition reaches the same place by another door — add a
 * permission to a role you hold and you have it — so it is bounded too.
 *
 * Every actor is an ordinary user unless the test is ABOUT the super admin:
 * a super admin passes every rule here, so it would prove nothing.
 *
 *   GM ── A ── A1        scoped actors hold their grants over A
 *    └─── B
 */
class RoleSubsetRuleTest extends TestCase
{
    use RefreshDatabase;

    private const PAY = 'hr.employees.viewSalary';

    /** @var array<string, Department> */
    private array $units = [];

    protected function setUp(): void
    {
        parent::setUp();

        $level = static fn (int $index): int => DepartmentLevel::cases()[$index]->value;

        $gm = Department::create(['name' => 'الإدارة العامة', 'code' => 'GM', 'level' => $level(0), 'parent_id' => null]);
        $a  = Department::create(['name' => 'إدارة أ', 'code' => 'A', 'level' => $level(1), 'parent_id' => $gm->id]);
        $a1 = Department::create(['name' => 'قسم أ-1', 'code' => 'A1', 'level' => $level(2), 'parent_id' => $a->id]);
        $b  = Department::create(['name' => 'إدارة ب', 'code' => 'B', 'level' => $level(1), 'parent_id' => $gm->id]);

        $this->units = compact('gm', 'a', 'a1', 'b');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * @param  array<int, string>  $permissions
     */
    private function role(array $permissions, string $name = ''): Role
    {
        $role = Role::create(['name' => $name !== '' ? $name : 'r-'.uniqid()]);
        $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));

        return $role;
    }

    /**
     * Give an EXTRA grant to a user who already exists — for actors who need
     * two grants of different reach.
     *
     * @param  array<int, string>  $permissions
     */
    private function grant(User $user, array $permissions, ScopeType $scope, ?int $departmentId = null): void
    {
        $user->roles()->attach($this->role($permissions)->id, [
            'scope_type'          => $scope->value,
            'scope_department_id' => $departmentId,
        ]);

        PermissionCache::flush();
    }

    /**
     * @return array<string, int>
     */
    private function overUnit(User $grantee, Role $role, string $unit = 'a1'): array
    {
        return [
            'user_id'             => $grantee->id,
            'role_id'             => $role->id,
            'scope_type'          => ScopeType::Unit->value,
            'scope_department_id' => $this->units[$unit]->id,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function companyWide(User $grantee, Role $role): array
    {
        return ['user_id' => $grantee->id, 'role_id' => $role->id, 'scope_type' => ScopeType::Company->value];
    }

    // ── The escalation GAP-16 was filed for ──────────────────────────────

    public function test_a_scoped_assigner_cannot_hand_themselves_a_unit_payroll_role(): void
    {
        $clerk = $this->actingAsScopedUser(['auth.roles.view', 'auth.roles.assign'], ScopeType::Unit, $this->units['a']->id);
        $payroll = $this->role(['hr.employees.view', self::PAY, 'hr.employees.viewIdentity', 'hr.employees.delete']);

        foreach ([$clerk, User::factory()->create()] as $grantee) {
            $this->postJson('/api/auth/roles/assign', $this->overUnit($grantee, $payroll, 'a'))
                ->assertForbidden()
                ->assertJsonPath('success', false);
        }

        $this->assertSame(0, UserRole::where('role_id', $payroll->id)->count());
        $this->assertFalse($clerk->fresh()->hasPermission(self::PAY));
    }

    public function test_the_refusal_names_exactly_what_the_assigner_lacks(): void
    {
        $this->actingAsScopedUser(['auth.roles.assign', 'hr.employees.view'], ScopeType::Unit, $this->units['a']->id);
        $role = $this->role(['hr.employees.view', self::PAY]);

        $label = Permission::where('name', self::PAY)->value('label');

        $this->postJson('/api/auth/roles/assign', $this->overUnit(User::factory()->create(), $role))
            ->assertForbidden()
            ->assertJsonPath('message', "لا يُمنح دور ولا يُسحب إلا ممن يملك كل ما يمنحه هذا الدور على النطاق نفسه. لا تملك: «{$label}».");
    }

    // ── What the rule allows ─────────────────────────────────────────────

    public function test_a_scoped_assigner_hands_out_what_they_hold_inside_their_reach(): void
    {
        $this->actingAsScopedUser(['auth.roles.assign', 'hr.employees.view', 'hr.employees.update'], ScopeType::Unit, $this->units['a']->id);
        $role = $this->role(['hr.employees.view', 'hr.employees.update']);

        $this->postJson('/api/auth/roles/assign', $this->overUnit(User::factory()->create(), $role))
            ->assertOk();
    }

    public function test_what_a_scoped_grant_would_not_confer_is_not_asked_of_the_assigner(): void
    {
        // Over a unit, departments.update is company-only (GAP-15): the grantee
        // gets nothing from it, so the assigner need not hold it either.
        $this->actingAsScopedUser(['auth.roles.assign', 'hr.employees.view'], ScopeType::Unit, $this->units['a']->id);
        $role = $this->role(['hr.employees.view', 'departments.update']);

        $this->postJson('/api/auth/roles/assign', $this->overUnit(User::factory()->create(), $role))
            ->assertOk();
    }

    // ── Company-wide assigners are bound too (decision 24) ───────────────

    public function test_a_company_wide_assigner_without_pay_cannot_hand_out_a_payroll_role(): void
    {
        $admin = $this->actingAsScopedUser(['auth.roles.assign', 'hr.employees.view'], ScopeType::Company);
        $payroll = $this->role(['hr.employees.view', self::PAY]);

        foreach ([$admin, User::factory()->create()] as $grantee) {
            $this->postJson('/api/auth/roles/assign', $this->companyWide($grantee, $payroll))->assertForbidden();
            $this->postJson('/api/auth/roles/assign', $this->overUnit($grantee, $payroll))->assertForbidden();
        }

        $this->assertSame(0, UserRole::where('role_id', $payroll->id)->count());
    }

    public function test_holding_it_company_wide_is_what_lets_them(): void
    {
        $this->actingAsScopedUser(['auth.roles.assign', 'hr.employees.view', self::PAY], ScopeType::Company);
        $payroll = $this->role(['hr.employees.view', self::PAY]);

        $this->postJson('/api/auth/roles/assign', $this->companyWide(User::factory()->create(), $payroll))->assertOk();
    }

    public function test_holding_a_permission_over_one_division_lets_them_grant_it_there_and_nowhere_wider(): void
    {
        $admin = $this->actingAsScopedUser(['auth.roles.assign', 'hr.employees.view'], ScopeType::Company);
        $this->grant($admin, [self::PAY], ScopeType::Unit, $this->units['a']->id);
        $payroll = $this->role(['hr.employees.view', self::PAY]);
        $grantee = User::factory()->create();

        $this->postJson('/api/auth/roles/assign', $this->overUnit($grantee, $payroll, 'a1'))->assertOk();
        $this->postJson('/api/auth/roles/assign', $this->overUnit($grantee, $payroll, 'b'))->assertForbidden();
        $this->postJson('/api/auth/roles/assign', $this->companyWide($grantee, $payroll))->assertForbidden();

        $this->assertSame(1, UserRole::where('user_id', $grantee->id)->count());
    }

    public function test_a_catalogue_read_held_from_any_grant_counts_as_held_everywhere(): void
    {
        // departments.view reads the whole org chart from a unit grant too
        // (decision 23), so holding it there IS holding it company-wide.
        $admin = $this->actingAsScopedUser(['auth.roles.assign', 'auth.roles.update'], ScopeType::Company);
        $this->grant($admin, ['departments.view'], ScopeType::Unit, $this->units['a']->id);
        $pickers = $this->role(['departments.view']);

        $this->postJson('/api/auth/roles/assign', $this->companyWide(User::factory()->create(), $pickers))->assertOk();
        $this->putJson("/api/auth/roles/{$this->role([])->id}", ['permissions' => ['departments.view']])->assertOk();
    }

    // ── Managed grants: measured against the units they would cover ──────

    public function test_a_managed_grant_is_measured_against_every_unit_the_grantee_manages(): void
    {
        $admin = $this->actingAsScopedUser(['auth.roles.assign', 'hr.employees.view'], ScopeType::Company);
        $this->grant($admin, [self::PAY], ScopeType::Unit, $this->units['a']->id);
        $payroll = $this->role(['hr.employees.view', self::PAY]);

        $head = Employee::factory()->create(['department_id' => $this->units['a1']->id]);
        $this->units['a1']->update(['manager_id' => $head->id]);
        $grantee = User::factory()->create(['employee_id' => $head->id]);
        $body = ['user_id' => $grantee->id, 'role_id' => $payroll->id, 'scope_type' => ScopeType::Managed->value];

        $this->postJson('/api/auth/roles/assign', $body)->assertOk();

        // Managing B as well puts part of the grant beyond the admin's pay reach.
        UserRole::where('user_id', $grantee->id)->delete();
        $this->units['b']->update(['manager_id' => $head->id]);

        $this->postJson('/api/auth/roles/assign', $body)->assertForbidden();
    }

    public function test_a_managed_grant_to_someone_who_manages_nothing_needs_the_permission_company_wide(): void
    {
        // Its units are whatever the grantee is appointed to next, so nothing
        // narrower than the company can vouch for it.
        $admin = $this->actingAsScopedUser(['auth.roles.assign', 'hr.employees.view'], ScopeType::Company);
        $this->grant($admin, [self::PAY], ScopeType::Unit, $this->units['a']->id);
        $payroll = $this->role(['hr.employees.view', self::PAY]);
        $grantee = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
        $body = ['user_id' => $grantee->id, 'role_id' => $payroll->id, 'scope_type' => ScopeType::Managed->value];

        $this->postJson('/api/auth/roles/assign', $body)->assertForbidden();

        $this->grant($admin, [self::PAY], ScopeType::Company);
        $this->postJson('/api/auth/roles/assign', $body)->assertOk();
    }

    // ── Revoking is bounded as granting is ───────────────────────────────

    public function test_nobody_takes_away_what_they_could_not_have_given(): void
    {
        $this->actingAsScopedUser(['auth.roles.assign', 'hr.employees.view'], ScopeType::Unit, $this->units['a']->id);
        $payroll = $this->role(['hr.employees.view', self::PAY]);
        $officer = User::factory()->create();
        $officer->roles()->attach($payroll->id, ['scope_type' => ScopeType::Unit->value, 'scope_department_id' => $this->units['a1']->id]);

        $this->postJson('/api/auth/roles/unassign', $this->overUnit($officer, $payroll))->assertForbidden();
        // Nor by leaving the scope out — "every grant of the role".
        $this->postJson('/api/auth/roles/unassign', ['user_id' => $officer->id, 'role_id' => $payroll->id])->assertForbidden();

        $this->assertSame(1, UserRole::where('user_id', $officer->id)->count());
    }

    public function test_a_role_the_assigner_could_give_they_can_also_take_away(): void
    {
        $this->actingAsScopedUser(['auth.roles.assign', 'hr.employees.view'], ScopeType::Unit, $this->units['a']->id);
        $viewer = $this->role(['hr.employees.view']);
        $clerk = User::factory()->create();
        $clerk->roles()->attach($viewer->id, ['scope_type' => ScopeType::Unit->value, 'scope_department_id' => $this->units['a1']->id]);

        $this->postJson('/api/auth/roles/unassign', $this->overUnit($clerk, $viewer))->assertOk();

        $this->assertSame(0, UserRole::where('user_id', $clerk->id)->count());
    }

    // ── super_admin: a super admin's to give and to take ─────────────────

    public function test_a_company_wide_assigner_cannot_crown_themselves_or_anyone(): void
    {
        // super_admin has no permission rows, so a subset rule alone would
        // pass it vacuously — the reason it has a rule of its own.
        $admin = $this->actingAsScopedUser(['auth.roles.assign'], ScopeType::Company);
        $super = Role::where('name', User::SUPER_ADMIN_ROLE)->firstOrFail();

        foreach ([$admin, User::factory()->create()] as $grantee) {
            $this->postJson('/api/auth/roles/assign', $this->companyWide($grantee, $super))
                ->assertForbidden()
                ->assertJsonPath('message', 'دور مدير النظام لا يمنحه ولا يسحبه إلا مدير نظام.');
        }

        $this->assertFalse($admin->fresh()->isSuperAdmin());
    }

    public function test_nor_depose_a_super_admin(): void
    {
        $this->actingAsScopedUser(['auth.roles.assign'], ScopeType::Company);
        $super = Role::where('name', User::SUPER_ADMIN_ROLE)->firstOrFail();

        $root = User::factory()->create();
        $root->roles()->attach($super->id);

        $this->postJson('/api/auth/roles/unassign', ['user_id' => $root->id, 'role_id' => $super->id])->assertForbidden();

        PermissionCache::flush();
        $this->assertTrue($root->fresh()->isSuperAdmin());
    }

    public function test_a_super_admin_can_do_both(): void
    {
        $this->actingAsAdmin();
        $super = Role::where('name', User::SUPER_ADMIN_ROLE)->firstOrFail();
        $grantee = User::factory()->create();

        $this->postJson('/api/auth/roles/assign', $this->companyWide($grantee, $super))->assertOk();
        $this->postJson('/api/auth/roles/unassign', ['user_id' => $grantee->id, 'role_id' => $super->id])->assertOk();
    }

    // ── Role definitions: the other door to the same place ───────────────

    public function test_an_editor_cannot_add_a_permission_they_lack_to_a_role_they_hold(): void
    {
        $editor = $this->actingAsScopedUser(['auth.roles.update', 'hr.employees.view'], ScopeType::Company);
        $own = $editor->roles()->firstOrFail();
        $withPay = [...$own->permissions->pluck('name')->all(), self::PAY];

        $this->putJson("/api/auth/roles/{$own->id}", ['permissions' => $withPay])->assertForbidden();

        PermissionCache::flush();
        $this->assertFalse($editor->fresh()->hasPermission(self::PAY));
    }

    public function test_nor_remove_one_they_lack_nor_delete_a_role_carrying_it(): void
    {
        $this->actingAsScopedUser(['auth.roles.update', 'auth.roles.delete', 'hr.employees.view'], ScopeType::Company);
        $payroll = $this->role(['hr.employees.view', self::PAY]);

        $this->putJson("/api/auth/roles/{$payroll->id}", ['permissions' => ['hr.employees.view']])->assertForbidden();
        $this->deleteJson("/api/auth/roles/{$payroll->id}")->assertForbidden();

        $this->assertTrue($payroll->fresh()->permissions->contains('name', self::PAY));
    }

    public function test_nor_create_a_role_carrying_it(): void
    {
        $this->actingAsScopedUser(['auth.roles.create'], ScopeType::Company);

        $this->postJson('/api/auth/roles', ['name' => 'payroll', 'permissions' => [self::PAY]])->assertForbidden();

        $this->assertDatabaseMissing('roles', ['name' => 'payroll']);
    }

    public function test_holding_it_only_over_a_division_is_not_enough_to_edit_a_definition(): void
    {
        // A role definition is company-wide: whoever holds the role over the
        // company would gain the permission through the edit.
        $editor = $this->actingAsScopedUser(['auth.roles.update'], ScopeType::Company);
        $this->grant($editor, [self::PAY], ScopeType::Unit, $this->units['a']->id);
        $clerks = $this->role(['hr.employees.view']);

        $this->putJson("/api/auth/roles/{$clerks->id}", ['permissions' => ['hr.employees.view', self::PAY]])
            ->assertForbidden();
    }

    public function test_an_editor_holding_every_change_company_wide_edits_freely(): void
    {
        $this->actingAsScopedUser(['auth.roles.create', 'auth.roles.update', 'auth.roles.delete', self::PAY], ScopeType::Company);
        $clerks = $this->role(['hr.employees.view']);

        // hr.employees.view is untouched, so the editor need not hold it.
        $this->putJson("/api/auth/roles/{$clerks->id}", ['permissions' => ['hr.employees.view', self::PAY]])->assertOk();
        $this->postJson('/api/auth/roles', ['name' => 'payroll', 'permissions' => [self::PAY]])->assertCreated();
        $this->deleteJson('/api/auth/roles/'.Role::where('name', 'payroll')->value('id'))->assertOk();
    }
}
