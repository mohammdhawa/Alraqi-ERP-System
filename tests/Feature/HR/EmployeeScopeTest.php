<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\RoleService;
use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Enums\AssignmentReason;
use App\Modules\HR\Enums\Gender;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeWarning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * GAP-3 — row-level authorization on employees (START_HERE §9, decisions 14,
 * 18 and 19).
 *
 * ⚠️ NO TEST HERE MAY ACT AS A SUPER ADMIN except the one asserting that the
 * bypass survives. Every other HR test uses `actingAsAdmin()`, which bypasses
 * scope, so those tests keep passing whatever this layer does — that is not
 * coverage, and it is why this file exists. Each actor holds exactly the
 * permissions and the one scoped grant it says.
 *
 * THE TREE, built fresh for every test:
 *
 *   GM  الإدارة العامة                 root employee
 *   ├── A   إدارة أ                      A-head (manages A), A staff
 *   │   └── A1  قسم أ-1                  A1 staff
 *   │       └── A1a مكتب أ-1-أ           A1a staff
 *   └── B   إدارة ب                      B staff
 *       └── B1  قسم ب-1                  B1 staff
 *
 *   plus one employee with no unit at all.
 */
class EmployeeScopeTest extends TestCase
{
    use RefreshDatabase;

    private const VIEW = ['hr.employees.view'];

    private const EDITOR = ['hr.employees.view', 'hr.employees.create', 'hr.employees.update', 'hr.employees.delete'];

    /** @var array<string, Department> */
    private array $units = [];

    /** @var array<string, Employee> */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();

        $level = static fn (int $index): int => DepartmentLevel::cases()[$index]->value;

        $gm  = Department::create(['name' => 'الإدارة العامة', 'code' => 'GM', 'level' => $level(0), 'parent_id' => null]);
        $a   = Department::create(['name' => 'إدارة أ', 'code' => 'A', 'level' => $level(1), 'parent_id' => $gm->id]);
        $a1  = Department::create(['name' => 'قسم أ-1', 'code' => 'A1', 'level' => $level(2), 'parent_id' => $a->id]);
        $a1a = Department::create(['name' => 'مكتب أ-1-أ', 'code' => 'A1a', 'level' => $level(3), 'parent_id' => $a1->id]);
        $b   = Department::create(['name' => 'إدارة ب', 'code' => 'B', 'level' => $level(1), 'parent_id' => $gm->id]);
        $b1  = Department::create(['name' => 'قسم ب-1', 'code' => 'B1', 'level' => $level(2), 'parent_id' => $b->id]);

        $this->units = compact('gm', 'a', 'a1', 'a1a', 'b', 'b1');

        foreach ($this->units as $key => $unit) {
            $this->people[$key] = Employee::factory()->create(['department_id' => $unit->id]);
        }

        $this->people['headA'] = Employee::factory()->create(['department_id' => $a->id]);
        $a->update(['manager_id' => $this->people['headA']->id]);

        $this->people['none'] = Employee::factory()->create(['department_id' => null]);
    }

    /**
     * Sign in as the head of division A, holding the given permissions over
     * «الوحدات التي أديرها».
     *
     * @param  array<int, string>  $permissions
     */
    private function actingAsHeadOfA(array $permissions = self::VIEW): User
    {
        return $this->actingAsScopedUser($permissions, ScopeType::Managed, employeeId: $this->people['headA']->id);
    }

    /**
     * @return array<int, int>
     */
    private function idsIn(string ...$keys): array
    {
        $ids = array_map(fn (string $key): int => $this->people[$key]->id, $keys);
        sort($ids);

        return $ids;
    }

    /**
     * Every id a caller can reach by walking the pages, in order.
     *
     * @return array<int, int>
     */
    private function pageThrough(int $perPage): array
    {
        $ids = [];
        $page = 1;

        do {
            $response = $this->getJson("/api/hr/employees?per_page={$perPage}&page={$page}")->assertOk();
            $ids = array_merge($ids, array_column($response->json('data'), 'id'));
            $lastPage = $response->json('meta.last_page');
            $page++;
        } while ($page <= $lastPage);

        return $ids;
    }

    // ── Lists filter ─────────────────────────────────────────────────────

    public function test_a_managed_head_lists_exactly_their_subtree_and_the_total_agrees(): void
    {
        $this->actingAsHeadOfA();

        $expected = $this->idsIn('a', 'a1', 'a1a', 'headA');

        $response = $this->getJson('/api/hr/employees?per_page=100')->assertOk();
        $this->assertSame(count($expected), $response->json('meta.total'));

        // Paging must return every in-scope row exactly once and nothing else:
        // an unscoped count query would report pages the caller cannot reach.
        $paged = $this->pageThrough(perPage: 3);
        $this->assertCount(count($expected), $paged);
        $this->assertSame(count($paged), count(array_unique($paged)));

        sort($paged);
        $this->assertSame($expected, $paged);
    }

    public function test_a_unit_grant_reaches_the_unit_and_below_but_not_above(): void
    {
        $this->actingAsScopedUser(self::VIEW, ScopeType::Unit, $this->units['a1']->id);

        $ids = array_column($this->getJson('/api/hr/employees?per_page=100')->assertOk()->json('data'), 'id');
        sort($ids);

        $this->assertSame($this->idsIn('a1', 'a1a'), $ids);
    }

    public function test_a_company_grant_sees_everyone_including_staff_with_no_unit(): void
    {
        $this->actingAsScopedUser(self::VIEW, ScopeType::Company);

        $this->getJson('/api/hr/employees?per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.total', count($this->people));
    }

    public function test_a_super_admin_is_unaffected(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/hr/employees?per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.total', count($this->people));

        $this->getJson("/api/hr/employees/{$this->people['b1']->id}")->assertOk();
    }

    public function test_a_managed_grant_on_an_account_with_no_employee_reaches_nothing(): void
    {
        // Managing is something an employee does. An account with no person
        // behind it manages no unit, so the grant resolves to an empty scope —
        // NOT to everything, which is the inversion decision 18 rejected.
        $this->actingAsScopedUser(self::VIEW, ScopeType::Managed);

        $this->getJson('/api/hr/employees')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    // ── Detail, update, delete: 403 with a clear message ─────────────────

    public function test_an_out_of_scope_employee_answers_403_with_an_arabic_message(): void
    {
        $this->actingAsHeadOfA();

        $this->getJson("/api/hr/employees/{$this->people['b1']->id}")
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'هذا الموظف خارج نطاق صلاحياتك.');

        // The deepest office of their own division is in reach.
        $this->getJson("/api/hr/employees/{$this->people['a1a']->id}")->assertOk();
    }

    /**
     * The policy denies rather than throws, so asking the Gate is a question:
     * it answers false for an out-of-scope row instead of raising a 403.
     */
    public function test_the_gate_answers_false_for_an_out_of_scope_row(): void
    {
        $user = $this->actingAsHeadOfA();

        $this->assertFalse(Gate::forUser($user)->allows('view', $this->people['b1']));
        $this->assertTrue(Gate::forUser($user)->allows('view', $this->people['a1a']));
        $this->assertFalse($user->can('view', $this->people['b1']));
    }

    /**
     * `can:` on the route, the destination check, the field gate, the
     * controller's warm-up and the Resource share one resolved scope per
     * permission: the managed-units lookup runs once per permission a request
     * uses, not once per caller.
     */
    public function test_a_transfer_resolves_each_scope_once(): void
    {
        $this->actingAsHeadOfA(['hr.employees.view', 'hr.employees.update', 'hr.employees.viewSalary', 'hr.employees.viewIdentity']);

        $lookups = 0;
        DB::listen(function ($query) use (&$lookups): void {
            if (str_contains($query->sql, '"manager_id" = ?') && str_contains($query->sql, 'from "departments"')) {
                $lookups++;
            }
        });

        $this->putJson("/api/hr/employees/{$this->people['a1a']->id}", ['department_id' => $this->units['a1']->id])->assertOk();

        // update (policy + destination), viewSalary and viewIdentity (field
        // gate + warm-up): three permissions, three lookups.
        $this->assertSame(3, $lookups);
    }

    public function test_staff_with_no_unit_are_outside_every_unit_scope(): void
    {
        $this->actingAsHeadOfA();

        $this->getJson("/api/hr/employees/{$this->people['none']->id}")->assertForbidden();
    }

    public function test_an_out_of_scope_employee_cannot_be_updated_or_deleted(): void
    {
        $this->actingAsHeadOfA(self::EDITOR);
        $target = $this->people['b'];

        $this->putJson("/api/hr/employees/{$target->id}", ['first_name' => 'مُعدَّل'])->assertForbidden();
        $this->deleteJson("/api/hr/employees/{$target->id}")->assertForbidden();

        $this->assertNotSame('مُعدَّل', $target->fresh()->first_name);
        $this->assertNotSoftDeleted($target);
    }

    public function test_an_in_scope_employee_can_be_updated(): void
    {
        $this->actingAsHeadOfA(self::EDITOR);

        $this->putJson("/api/hr/employees/{$this->people['a1']->id}", ['first_name' => 'مُعدَّل'])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'مُعدَّل');
    }

    // ── Where a person is PUT must be in scope too ───────────────────────

    public function test_create_into_a_unit_outside_scope_is_refused(): void
    {
        $this->actingAsHeadOfA(self::EDITOR);

        $payload = ['employee_number' => $this->nextKoboNumber(), 'first_name' => 'جديد', 'middle_name' => 'أحمد', 'last_name' => 'موظف', 'gender' => Gender::Male->value];

        $this->postJson('/api/hr/employees', $payload + ['department_id' => $this->units['b1']->id])
            ->assertForbidden()
            ->assertJsonPath('message', 'لا يمكنك إسناد موظف إلى وحدة تنظيمية خارج نطاق صلاحياتك.');

        // No unit at all is outside every unit scope as well.
        $this->postJson('/api/hr/employees', $payload)->assertForbidden();

        $this->assertDatabaseMissing('employees', ['first_name' => 'جديد']);

        $this->postJson('/api/hr/employees', $payload + ['department_id' => $this->units['a1a']->id])->assertCreated();
    }

    public function test_an_in_scope_employee_cannot_be_transferred_out_of_scope(): void
    {
        $this->actingAsHeadOfA(self::EDITOR);
        $person = $this->people['a1'];

        $this->putJson("/api/hr/employees/{$person->id}", ['department_id' => $this->units['b1']->id])
            ->assertForbidden();

        $this->assertSame($this->units['a1']->id, $person->fresh()->department_id);

        // Moving within the subtree is fine.
        $this->putJson("/api/hr/employees/{$person->id}", ['department_id' => $this->units['a1a']->id])->assertOk();
    }

    public function test_a_posting_cannot_move_someone_out_of_scope_either(): void
    {
        $this->actingAsHeadOfA(['hr.assignments.view', 'hr.assignments.create']);
        $person = $this->people['a1'];

        $this->postJson("/api/hr/employees/{$person->id}/assignments", [
            'department_id' => $this->units['b1']->id,
            'from_date'     => now()->toDateString(),
            'reason'        => AssignmentReason::Transfer->value,
        ])->assertForbidden();

        $this->assertSame($this->units['a1']->id, $person->fresh()->department_id);

        $this->getJson("/api/hr/employees/{$this->people['b1']->id}/assignments")->assertForbidden();
        $this->getJson("/api/hr/employees/{$person->id}/assignments")->assertOk();
    }

    public function test_warnings_follow_the_employee_they_were_issued_to(): void
    {
        $this->actingAsHeadOfA(['hr.warnings.view', 'hr.warnings.create', 'hr.warnings.update', 'hr.warnings.delete']);

        $outside = EmployeeWarning::create([
            'employee_id' => $this->people['b1']->id,
            'type'        => 'مخالفة',
            'issued_on'   => '2026-06-01',
        ]);

        $this->getJson("/api/hr/employees/{$this->people['b1']->id}/warnings")->assertForbidden();
        $this->postJson("/api/hr/employees/{$this->people['b1']->id}/warnings", [
            'type' => 'مخالفة', 'issued_on' => '2026-06-02',
        ])->assertForbidden();
        $this->putJson("/api/hr/warnings/{$outside->id}", ['type' => 'مُعدَّل', 'issued_on' => '2026-06-01'])->assertForbidden();
        $this->deleteJson("/api/hr/warnings/{$outside->id}")->assertForbidden();

        $this->assertNotSoftDeleted($outside);
        $this->getJson("/api/hr/employees/{$this->people['a1']->id}/warnings")->assertOk();
    }

    // ── Scope is per permission ──────────────────────────────────────────

    public function test_the_scope_consulted_is_the_one_on_the_grant_carrying_the_verb(): void
    {
        // Company-wide reading through one role, editing over one section
        // through another. Editing must not borrow the reading grant's reach.
        $user = $this->actingAsScopedUser(['hr.employees.view'], ScopeType::Company);

        $editor = Role::create(['name' => 'editor-a1']);
        $editor->permissions()->sync(Permission::where('name', 'hr.employees.update')->pluck('id'));

        app(RoleService::class)->assign($user->id, $editor->id, ScopeType::Unit, $this->units['a1']->id);

        $this->getJson("/api/hr/employees/{$this->people['b']->id}")->assertOk();
        $this->putJson("/api/hr/employees/{$this->people['b']->id}", ['first_name' => 'x'])->assertForbidden();
        $this->putJson("/api/hr/employees/{$this->people['a1']->id}", ['first_name' => 'x'])->assertOk();
    }

    // ── Changes take effect at once ──────────────────────────────────────

    public function test_revoking_a_scope_takes_effect_on_the_next_request(): void
    {
        $user = $this->actingAsHeadOfA();
        $roleId = $user->roles()->value('roles.id');
        $roles = app(RoleService::class);

        $roles->assign($user->id, $roleId, ScopeType::Company);
        $this->getJson("/api/hr/employees/{$this->people['b1']->id}")->assertOk();

        // The permission cache is warm with the company grant now. Without the
        // version bump in unassign(), the next request would still be let in.
        $roles->unassign($user->id, $roleId, ScopeType::Company);
        $this->getJson("/api/hr/employees/{$this->people['b1']->id}")->assertForbidden();
    }

    public function test_a_manager_moved_to_another_division_takes_their_reach_with_them(): void
    {
        $this->actingAsHeadOfA();
        $this->getJson("/api/hr/employees/{$this->people['a1']->id}")->assertOk();

        // No grant changes and no cache flush: the tree is the record of who
        // manages what, and it is read fresh.
        $this->units['a']->update(['manager_id' => null]);
        $this->units['b']->update(['manager_id' => $this->people['headA']->id]);

        $this->getJson("/api/hr/employees/{$this->people['a1']->id}")->assertForbidden();
        $this->getJson("/api/hr/employees/{$this->people['b1']->id}")->assertOk();
    }

    public function test_staff_of_a_deleted_office_stay_in_their_division_heads_scope(): void
    {
        $this->actingAsHeadOfA();

        $this->units['a1a']->delete();

        $this->getJson("/api/hr/employees/{$this->people['a1a']->id}")->assertOk();
    }

    public function test_the_subtree_walk_is_bounded_by_the_tier_count_not_the_unit_count(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $ids = Department::subtreeIds([$this->units['gm']->id]);

        $this->assertCount(count($this->units), $ids);
        $this->assertLessThanOrEqual(count(DepartmentLevel::cases()) - 1, $queries);
    }
}
