<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Support\PermissionCache;
use App\Modules\Auth\Support\PermissionReach;
use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * GAP-15 — a scoped grant may confer only what something enforces its scope
 * for (START_HERE §9, decisions 21–23).
 *
 * The gap was not a missing filter. Outside the employee routes a permission
 * was checked by name alone, so a grant scoped to ONE section acted
 * company-wide — and three families edit what scope itself is computed from:
 * `departments.update` (who manages which unit), `auth.users.*` (which account
 * is which employee) and role definitions (what a role carries). The paths
 * below are those escalations, each asserted closed from a unit-scoped grant
 * and still open from a company-wide one, so a pass means the RULE works and
 * not merely that the verb is refused to everyone.
 *
 * No test acts as a super admin: one would pass every assertion here for the
 * wrong reason.
 *
 *   GM ── A ── A1        the scoped actors hold their grants over A
 *    └─── B
 */
class PermissionReachTest extends TestCase
{
    use RefreshDatabase;

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

    /**
     * @param  array<int, string>  $permissions
     */
    private function actingOverDivisionA(array $permissions): User
    {
        return $this->actingAsScopedUser($permissions, ScopeType::Unit, $this->units['a']->id);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function actingCompanyWide(array $permissions): User
    {
        return $this->actingAsScopedUser($permissions, ScopeType::Company);
    }

    // ── The escalation paths, closed from a scoped grant ─────────────────

    public function test_a_scoped_grant_cannot_set_who_manages_a_unit(): void
    {
        // «الوحدات التي أديرها» follows manager_id. Making yourself manager of
        // another division was a way to put it inside your own scope.
        $self = Employee::factory()->create(['department_id' => $this->units['a']->id]);
        $this->actingOverDivisionA(['departments.view', 'departments.update']);

        $this->patchJson("/api/departments/{$this->units['b']->id}", ['manager_id' => $self->id])
            ->assertForbidden();
        // Not even inside the grant's own unit: department writes have no
        // scope enforcement yet, so a scoped grant confers none of them.
        $this->patchJson("/api/departments/{$this->units['a1']->id}", ['name' => 'x'])
            ->assertForbidden();

        $this->assertNull($this->units['b']->fresh()->manager_id);
    }

    public function test_the_same_permission_from_a_company_grant_still_works(): void
    {
        $this->actingCompanyWide(['departments.view', 'departments.update']);

        $this->patchJson("/api/departments/{$this->units['a1']->id}", ['name' => 'قسم مُعدَّل'])
            ->assertOk();
    }

    public function test_a_scoped_grant_cannot_relink_an_account_to_another_employee(): void
    {
        // Linking your own account to a senior manager's employee record was a
        // way to borrow their subtree through a managed grant.
        $user = $this->actingOverDivisionA(['auth.users.view', 'auth.users.update']);
        $senior = Employee::factory()->create(['department_id' => $this->units['gm']->id]);

        $this->putJson("/api/auth/users/{$user->id}", ['employee_id' => $senior->id])
            ->assertForbidden();
        $this->getJson('/api/auth/users')->assertForbidden();

        $this->assertNotSame($senior->id, $user->fresh()->employee_id);
    }

    public function test_a_scoped_grant_cannot_edit_what_a_role_carries(): void
    {
        // Role definitions are company-wide objects: editing one changes every
        // holder, and a new one could be granted to yourself over your own unit
        // (which the assign reach check allows) carrying anything at all.
        $role = Role::create(['name' => 'clerk']);
        $this->actingOverDivisionA(['auth.roles.view', 'auth.roles.create', 'auth.roles.update', 'auth.roles.delete']);

        $this->postJson('/api/auth/roles', ['name' => 'escalator', 'permissions' => ['departments.update']])
            ->assertForbidden();
        $this->putJson("/api/auth/roles/{$role->id}", ['permissions' => ['auth.users.update']])
            ->assertForbidden();
        $this->deleteJson("/api/auth/roles/{$role->id}")->assertForbidden();

        $this->assertDatabaseMissing('roles', ['name' => 'escalator']);
        $this->assertCount(0, $role->fresh()->permissions);
    }

    public function test_a_scoped_grant_cannot_read_the_unscoped_audit_trail(): void
    {
        $this->actingOverDivisionA(['auth.audit.view', 'auth.audit.viewValues']);

        $this->getJson('/api/auth/audit-logs')->assertForbidden();
    }

    // ── What a scoped grant still confers ────────────────────────────────

    public function test_catalogue_reads_are_honoured_from_a_scoped_grant(): void
    {
        // The unit, title and role pickers a scoped user's screens need.
        $this->actingOverDivisionA(['departments.view', 'hr.job_titles.view', 'auth.roles.view']);

        $this->getJson('/api/departments')->assertOk();
        $this->getJson('/api/hr/job-titles')->assertOk();
        $this->getJson('/api/auth/roles')->assertOk();
    }

    public function test_row_scoped_permissions_are_honoured_and_still_scoped(): void
    {
        $inside = Employee::factory()->create(['department_id' => $this->units['a1']->id]);
        $outside = Employee::factory()->create(['department_id' => $this->units['b']->id]);
        $this->actingOverDivisionA(['hr.employees.view']);

        $this->getJson("/api/hr/employees/{$inside->id}")->assertOk();
        $this->getJson("/api/hr/employees/{$outside->id}")->assertForbidden();
    }

    public function test_a_scoped_role_assigner_can_still_assign_within_reach(): void
    {
        // The reason auth.roles.assign was split out rather than made
        // company-only with the rest of auth.roles.*.
        $grantee = User::factory()->create();
        $role = Role::create(['name' => 'clerk']);
        $this->actingOverDivisionA(['auth.roles.view', 'auth.roles.assign']);

        $this->postJson('/api/auth/roles/assign', [
            'user_id'             => $grantee->id,
            'role_id'             => $role->id,
            'scope_type'          => ScopeType::Unit->value,
            'scope_department_id' => $this->units['a1']->id,
        ])->assertOk();

        $this->postJson('/api/auth/roles/assign', [
            'user_id'             => $grantee->id,
            'role_id'             => $role->id,
            'scope_type'          => ScopeType::Unit->value,
            'scope_department_id' => $this->units['b']->id,
        ])->assertForbidden();
    }

    // ── A scoped grant that would confer nothing is refused ──────────────

    /**
     * Found in review, 2026-09-26: over a unit, a role made only of
     * company-only permissions confers nothing — yet assigning it answered
     * 200 and listed the grant, telling the administrator they gave something
     * they did not.
     *
     * @return array{0: User, 1: array<string, mixed>}
     */
    private function assignOverA(array $rolePermissions, ScopeType $scope = ScopeType::Unit): array
    {
        $grantee = User::factory()->create();
        $role = Role::create(['name' => 'r-'.uniqid()]);
        $role->permissions()->sync(Permission::whereIn('name', $rolePermissions)->pluck('id'));

        $body = ['user_id' => $grantee->id, 'role_id' => $role->id, 'scope_type' => $scope->value];

        if ($scope === ScopeType::Unit) {
            $body['scope_department_id'] = $this->units['a1']->id;
        }

        return [$grantee, $body];
    }

    public function test_a_scoped_grant_of_a_wholly_company_only_role_is_a_422(): void
    {
        $this->actingCompanyWide(['auth.roles.assign']);
        [$grantee, $body] = $this->assignOverA(['departments.update', 'auth.users.view']);

        $this->postJson('/api/auth/roles/assign', $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scope_type'])
            // The message states the fact and the remedy every assigner has —
            // not "grant it company-wide", which a scoped assigner cannot do.
            ->assertJsonPath('errors.scope_type.0', 'لن يمنح هذا الإسناد أي صلاحية على هذا النطاق: كل صلاحيات هذا الدور لا تعمل إلا بإسناد على مستوى «الشركة كاملة». اختر دوراً آخر لهذا النطاق.');

        $this->assertCount(0, $grantee->fresh()->roles);
    }

    public function test_the_same_role_company_wide_or_partly_scoped_is_accepted(): void
    {
        // The assigner holds what they hand out — GAP-16's subset rule, which
        // RoleSubsetRuleTest covers; this test is about the inert check only.
        $this->actingCompanyWide(['auth.roles.assign', 'departments.update', 'hr.employees.view']);

        // Company-wide, the same role confers everything it carries.
        [, $body] = $this->assignOverA(['departments.update'], ScopeType::Company);
        $this->postJson('/api/auth/roles/assign', $body)->assertOk();

        // Partly company-only: the row-scoped part still works over the unit.
        [, $body] = $this->assignOverA(['departments.update', 'hr.employees.view']);
        $this->postJson('/api/auth/roles/assign', $body)->assertOk();

        // No permissions at all confers nothing at ANY scope; not this rule.
        [, $body] = $this->assignOverA([]);
        $this->postJson('/api/auth/roles/assign', $body)->assertOk();
    }

    public function test_an_inert_scoped_grant_can_still_be_revoked(): void
    {
        $this->actingCompanyWide(['auth.roles.assign']);
        [$grantee, $body] = $this->assignOverA(['departments.update']);

        // Written around the request, as a seeder may.
        $grantee->roles()->attach($body['role_id'], [
            'scope_type'          => ScopeType::Unit->value,
            'scope_department_id' => $body['scope_department_id'],
        ]);

        $this->postJson('/api/auth/roles/unassign', $body)->assertOk();
        $this->assertCount(0, $grantee->fresh()->roles);
    }

    // ── GAP-16: scoped assigners, once the subset rule bounds them ───────

    /**
     * From 2026-09-26 until GAP-16 closed, a scoped grant of a role carrying
     * auth.roles.assign was a 422, and so was adding assign to a role already
     * held narrowly. The subset rule made both safe (2026-09-28): an assigner
     * hands out only what they hold over the same units. RoleSubsetRuleTest
     * covers the rule itself; this pins that the interim refusals are gone.
     */
    public function test_a_scoped_grant_of_a_role_that_can_assign_is_accepted(): void
    {
        $this->actingCompanyWide(['auth.roles.assign', 'hr.employees.view']);

        foreach ([ScopeType::Unit, ScopeType::Company] as $scope) {
            [$grantee, $body] = $this->assignOverA(['auth.roles.assign', 'hr.employees.view'], $scope);

            $this->postJson('/api/auth/roles/assign', $body)->assertOk();
            $this->assertCount(1, $grantee->fresh()->roles, $scope->name);
        }
    }

    public function test_assign_can_be_added_to_a_role_already_held_over_a_unit(): void
    {
        $this->actingCompanyWide(['auth.roles.update', 'auth.roles.assign']);
        $clerks = $this->roleWith('clerks', ['hr.employees.view']);
        User::factory()->create()->roles()->attach($clerks->id, [
            'scope_type'          => ScopeType::Unit->value,
            'scope_department_id' => $this->units['a']->id,
        ]);

        // Only the addition is measured against the editor: hr.employees.view
        // stays, and they need not hold it.
        $this->putJson("/api/auth/roles/{$clerks->id}", ['permissions' => ['hr.employees.view', 'auth.roles.assign']])
            ->assertOk();

        $this->assertTrue($clerks->fresh()->permissions->contains('name', 'auth.roles.assign'));
    }

    // ── One effective set, for every reader ──────────────────────────────

    public function test_me_reports_what_a_scoped_grant_actually_confers(): void
    {
        // A client builds its screens from this list. Listing a verb the
        // server will refuse would put a button in front of a 403.
        $this->actingOverDivisionA(['hr.employees.view', 'departments.view', 'departments.update', 'auth.audit.view']);

        $permissions = $this->getJson('/api/auth/me')->assertOk()->json('data.permissions');

        sort($permissions);
        $this->assertSame(['departments.view', 'hr.employees.view'], $permissions);
    }

    public function test_a_company_grant_beside_a_scoped_one_confers_its_own_verbs(): void
    {
        $user = $this->actingOverDivisionA(['departments.update']);

        $company = Role::create(['name' => 'directory-'.uniqid()]);
        $company->permissions()->sync(Permission::where('name', 'auth.users.view')->pluck('id'));
        $user->roles()->attach($company->id, ['scope_type' => ScopeType::Company->value]);
        PermissionCache::flush();

        $this->assertTrue($user->fresh()->hasPermission('auth.users.view'));
        $this->assertFalse($user->fresh()->hasPermission('departments.update'));
    }

    // ── The classification is a decision, not a default ──────────────────

    public function test_every_permission_is_classified_on_purpose(): void
    {
        // PermissionReach is an allow-list: anything missing from it is
        // company-only. That is the safe failure — but it must also be a
        // CONSCIOUS one, so the company-only set is written out here in full.
        // A permission added to the catalogue fails this test until someone
        // decides which side it belongs on and says so.
        $this->seedRbac();

        $catalogue = Permission::query()->pluck('name')->all();
        $honoured = array_merge(PermissionReach::ROW_SCOPED, PermissionReach::CATALOGUE_READS);

        // No typo in the allow-list: every entry names a real permission.
        $this->assertSame([], array_values(array_diff($honoured, $catalogue)));

        $companyOnly = array_values(array_diff($catalogue, $honoured));
        sort($companyOnly);

        $this->assertSame([
            'auth.audit.view', 'auth.audit.viewValues',
            'auth.roles.create', 'auth.roles.delete', 'auth.roles.update',
            'auth.users.create', 'auth.users.delete', 'auth.users.update', 'auth.users.view',
            'departments.create', 'departments.delete', 'departments.update',
            'hr.job_titles.create', 'hr.job_titles.delete', 'hr.job_titles.update',
        ], $companyOnly);
    }

    // ── The split, on data that predates it ──────────────────────────────

    public function test_the_migration_gives_assign_to_every_role_that_could_assign(): void
    {
        $this->seedRbac();

        $admins = Role::create(['name' => 'admins']);
        $viewers = Role::create(['name' => 'viewers']);
        $admins->permissions()->sync(Permission::whereIn('name', ['auth.roles.update'])->pluck('id'));
        $viewers->permissions()->sync(Permission::whereIn('name', ['auth.roles.view'])->pluck('id'));

        // The deploy-time shape: the permission does not exist yet.
        DB::table('role_permissions')
            ->where('permission_id', Permission::where('name', 'auth.roles.assign')->value('id'))
            ->delete();
        Permission::where('name', 'auth.roles.assign')->delete();

        $migration = require database_path('migrations/Auth/2026_09_26_100000_split_auth_roles_assign_permission.php');
        $migration->up();
        $migration->up(); // idempotent

        $this->assertSame(1, Permission::where('name', 'auth.roles.assign')->count());
        $this->assertTrue($admins->fresh()->permissions->contains('name', 'auth.roles.assign'));
        $this->assertFalse($viewers->fresh()->permissions->contains('name', 'auth.roles.assign'));
    }

    /**
     * The split migration, run against the shape a deploy finds: the catalogue
     * without `auth.roles.assign`.
     */
    private function splitMigration(): object
    {
        DB::table('role_permissions')
            ->where('permission_id', Permission::where('name', 'auth.roles.assign')->value('id'))
            ->delete();
        Permission::where('name', 'auth.roles.assign')->delete();

        return require database_path('migrations/Auth/2026_09_26_100000_split_auth_roles_assign_permission.php');
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function roleWith(string $name, array $permissions): Role
    {
        $role = Role::create(['name' => $name]);
        $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));

        return $role;
    }

    public function test_the_migration_refuses_to_turn_a_scoped_update_grant_into_a_scoped_assign(): void
    {
        // Found in review: the copy ignored scope, so a role carrying update
        // granted over a unit would have become exactly the scoped
        // auth.roles.assign GAP-16 says not to grant — silently.
        $this->seedRbac();
        $admins = $this->roleWith('section-admins', ['auth.roles.update']);
        User::factory()->create()->roles()->attach($admins->id, [
            'scope_type'          => ScopeType::Unit->value,
            'scope_department_id' => $this->units['a']->id,
        ]);

        $migration = $this->splitMigration();

        try {
            $migration->up();
            $this->fail('The migration ran over a scoped auth.roles.update grant.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('section-admins', $e->getMessage());
        }

        // Refused BEFORE writing anything.
        $this->assertSame(0, Permission::where('name', 'auth.roles.assign')->count());
    }

    public function test_the_migration_reports_each_scoped_grant_that_stops_conferring_something(): void
    {
        // "Nobody's reach changes" was only true of assigning: this release
        // strips company-only permissions from every existing scoped grant.
        // Intended — but said, grant by grant.
        $this->seedRbac();
        $clerks = $this->roleWith('dept-clerks', ['departments.update', 'hr.employees.view']);
        $holder = User::factory()->create();
        $holder->roles()->attach($clerks->id, [
            'scope_type'          => ScopeType::Unit->value,
            'scope_department_id' => $this->units['a']->id,
        ]);

        Log::spy();

        $this->splitMigration()->up();

        Log::shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $line): bool => str_contains($line, 'dept-clerks')
                && str_contains($line, 'departments.update')
                && ! str_contains($line, 'hr.employees.view'),
        );
    }

    public function test_the_migration_ignores_scoped_grants_of_deleted_accounts(): void
    {
        // Found in review: UserService::delete() keeps a deleted account's
        // user_roles rows, and unassign refuses a deleted user — so refusing on
        // one blocked the deploy on a row no API call could clear.
        $this->seedRbac();
        $admins = $this->roleWith('section-admins', ['auth.roles.update', 'departments.update']);
        $gone = User::factory()->create();
        $gone->roles()->attach($admins->id, [
            'scope_type'          => ScopeType::Unit->value,
            'scope_department_id' => $this->units['a']->id,
        ]);
        $gone->delete();

        Log::spy();

        $this->splitMigration()->up();

        $this->assertTrue($admins->fresh()->permissions->contains('name', 'auth.roles.assign'));
        // Nor is the dead grant reported as shrinking.
        Log::shouldNotHaveReceived('warning');
    }

    public function test_rolling_back_refuses_while_a_role_holds_assign_without_update(): void
    {
        // Found in review: down() stripped assign from every role, leaving a
        // role created after the deploy with assign alone unable to assign.
        // Handing it update instead would add role EDITING — so it refuses,
        // and names the role.
        $this->seedRbac();
        $migration = $this->splitMigration();
        $migration->up();

        $this->roleWith('assign-only', ['auth.roles.view', 'auth.roles.assign']);

        try {
            $migration->down();
            $this->fail('The rollback stranded a role holding only auth.roles.assign.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('assign-only', $e->getMessage());
        }

        $this->assertSame(1, Permission::where('name', 'auth.roles.assign')->count());
    }

    public function test_rolling_back_proceeds_when_every_assign_holder_kept_update(): void
    {
        $this->seedRbac();
        $admins = $this->roleWith('admins', ['auth.roles.update']);
        $migration = $this->splitMigration();
        $migration->up();

        $this->assertTrue($admins->fresh()->permissions->contains('name', 'auth.roles.assign'));

        $migration->down();

        $this->assertSame(0, Permission::where('name', 'auth.roles.assign')->count());
        $this->assertTrue($admins->fresh()->permissions->contains('name', 'auth.roles.update'));
    }

    public function test_rolling_back_is_not_blocked_by_a_deleted_role(): void
    {
        // Found in the pre-commit scan: down() counted soft-deleted roles, so
        // a role nobody can use would have stopped the rollback. up() already
        // leaves deleted roles out; down() now matches.
        $this->seedRbac();
        $migration = $this->splitMigration();
        $migration->up();

        $this->roleWith('retired-assigners', ['auth.roles.assign'])->delete();

        $migration->down();

        $this->assertSame(0, Permission::where('name', 'auth.roles.assign')->count());
    }
}
