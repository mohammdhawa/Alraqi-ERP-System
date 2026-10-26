<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Support\PermissionCache;
use App\Modules\Departments\Enums\DepartmentLevel;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Enums\AssignmentReason;
use App\Modules\HR\Enums\Gender;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GAP-3 × C11 — a field gate is a question about the ROW once grants carry
 * scopes.
 *
 * Found in review of the first GAP-3 pass: rows were scoped through
 * `AccessScope`, but the pay and identity gates still asked `hasPermission()`,
 * which is scope-blind. So the case every test here builds — a company-wide
 * `hr.employees.view` beside a `viewSalary` granted over ONE section — read
 * the pay of the whole company, and could write it wherever the broader update
 * grant reached. The audit trail's per-type gate had the same hole.
 *
 * THE ACTOR: grant 1 is company-wide and carries the verbs; grant 2 carries
 * `viewSalary` + `viewIdentity` over section A1 only. Nothing here acts as a
 * super admin — one would pass every assertion for the wrong reason.
 *
 *   GM ── A ── A1 ── A1a      (A1 and A1a are inside the pay grant)
 *    └─── B                   (outside it)
 */
class EmployeeScopedFieldsTest extends TestCase
{
    use RefreshDatabase;

    private const VERBS = [
        'hr.employees.view', 'hr.employees.create', 'hr.employees.update',
        'auth.audit.view', 'auth.audit.viewValues',
    ];

    /** @var array<string, Department> */
    private array $units = [];

    protected function setUp(): void
    {
        parent::setUp();

        $level = static fn (int $index): int => DepartmentLevel::cases()[$index]->value;

        $gm  = Department::create(['name' => 'الإدارة العامة', 'code' => 'GM', 'level' => $level(0), 'parent_id' => null]);
        $a   = Department::create(['name' => 'إدارة أ', 'code' => 'A', 'level' => $level(1), 'parent_id' => $gm->id]);
        $a1  = Department::create(['name' => 'قسم أ-1', 'code' => 'A1', 'level' => $level(2), 'parent_id' => $a->id]);
        $a1a = Department::create(['name' => 'مكتب أ-1-أ', 'code' => 'A1a', 'level' => $level(3), 'parent_id' => $a1->id]);
        $b   = Department::create(['name' => 'إدارة ب', 'code' => 'B', 'level' => $level(1), 'parent_id' => $gm->id]);

        $this->units = compact('gm', 'a', 'a1', 'a1a', 'b');
    }

    /**
     * Company-wide verbs, section-A1 pay and identity.
     *
     * @param  array<int, string>  $fieldPermissions
     */
    private function actingAsPayrollClerkForA1(
        array $fieldPermissions = ['hr.employees.viewSalary', 'hr.employees.viewIdentity'],
        ScopeType $fieldScope = ScopeType::Unit,
        array $extraVerbs = [],
    ): User {
        $user = $this->actingAsScopedUser([...self::VERBS, ...$extraVerbs], ScopeType::Company);

        $fields = Role::create(['name' => 'payroll-a1-'.uniqid()]);
        $fields->permissions()->sync(Permission::whereIn('name', $fieldPermissions)->pluck('id'));

        $user->roles()->attach($fields->id, [
            'scope_type'          => $fieldScope->value,
            'scope_department_id' => $fieldScope === ScopeType::Unit ? $this->units['a1']->id : null,
        ]);
        PermissionCache::flush();

        return $user;
    }

    private function paidEmployeeIn(string $unit): Employee
    {
        return Employee::factory()->create([
            'department_id' => $this->units[$unit]->id,
            'salary_mode'   => 2,
            'agreed_salary' => '500000.00',
            'national_id'   => 'NID-'.$unit.'-'.uniqid(),
        ]);
    }

    // ── Read ─────────────────────────────────────────────────────────────

    public function test_pay_and_identity_are_shown_only_where_the_field_grant_reaches(): void
    {
        $this->actingAsPayrollClerkForA1();

        $inside = $this->paidEmployeeIn('a1a');
        $outside = $this->paidEmployeeIn('b');

        $shown = $this->getJson("/api/hr/employees/{$inside->id}")->assertOk()->json('data');
        $this->assertSame('500000.00', $shown['agreed_salary']);
        $this->assertSame($inside->national_id, $shown['national_id']);

        // The ROW is readable — the view grant is company-wide — but its pay
        // and identity are not, and they are absent rather than null (C11).
        $hidden = $this->getJson("/api/hr/employees/{$outside->id}")->assertOk()->json('data');
        $this->assertArrayNotHasKey('agreed_salary', $hidden);
        $this->assertArrayNotHasKey('organizational_grade', $hidden);
        $this->assertArrayNotHasKey('national_id', $hidden);
    }

    public function test_the_list_gates_each_row_by_its_own_unit(): void
    {
        $this->actingAsPayrollClerkForA1();

        $inside = $this->paidEmployeeIn('a1');
        $outside = $this->paidEmployeeIn('b');

        $rows = collect($this->getJson('/api/hr/employees?per_page=100')->assertOk()->json('data'))->keyBy('id');

        $this->assertArrayHasKey('agreed_salary', $rows[$inside->id]);
        $this->assertArrayNotHasKey('agreed_salary', $rows[$outside->id]);
    }

    /**
     * Two claims, asserted separately because either can break alone:
     *
     *   1. ONCE PER REQUEST, not per row — the walk count is bounded by the
     *      field permissions and the tier count, whatever the page size.
     *   2. BEFORE THE RESOURCE — every walk happens before the rows' relations
     *      are loaded for rendering, i.e. in the controller's warm-up. The
     *      Resource contract forbids queries, and claim 1 alone cannot show it
     *      is kept: with the warm-up removed, the Resource's own first lookup
     *      would still resolve each permission exactly once and stay under the
     *      bound. What changes is WHEN the walks run — after the render
     *      relations, from inside rendering — and that is what the second
     *      assertion catches.
     *
     * The marker is the `department` eager load (`"departments"."id" in`),
     * which both paths issue for the Resource after the warm-up and before
     * rendering; the walk itself is `"parent_id" in` and never matches it.
     */
    public function test_the_list_resolves_field_scopes_once_and_before_rendering(): void
    {
        $this->actingAsPayrollClerkForA1();

        foreach (range(1, 6) as $i) {
            $this->paidEmployeeIn($i % 2 === 0 ? 'a1' : 'b');
        }

        $this->assertFieldScopesResolvedBeforeRendering('/api/hr/employees?per_page=100');
    }

    public function test_the_detail_resolves_field_scopes_before_rendering(): void
    {
        $this->actingAsPayrollClerkForA1();

        $employee = $this->paidEmployeeIn('b');

        $this->assertFieldScopesResolvedBeforeRendering("/api/hr/employees/{$employee->id}");
    }

    private function assertFieldScopesResolvedBeforeRendering(string $uri): void
    {
        $walks = [];
        $renderLoad = null;
        $position = 0;

        DB::listen(function ($query) use (&$walks, &$renderLoad, &$position): void {
            $position++;

            if (str_contains($query->sql, 'from "departments"') && str_contains($query->sql, '"parent_id" in')) {
                $walks[] = $position;
            }

            if ($renderLoad === null && str_contains($query->sql, '"departments"."id" in')) {
                $renderLoad = $position;
            }
        });

        $this->getJson($uri)->assertOk();

        $this->assertNotSame([], $walks, 'A unit-scoped grant must walk the tree at least once.');
        $this->assertNotNull($renderLoad);

        // 1. Two field permissions, each walking at most (tiers - 1) levels.
        //    A per-row resolution would multiply this by the page size.
        $this->assertLessThanOrEqual(2 * (count(DepartmentLevel::cases()) - 1), count($walks));

        // 2. All of it before the render relations — none from inside the
        //    Resource.
        $this->assertLessThan($renderLoad, max($walks), 'The field scopes were resolved during rendering: the controller did not warm them.');
    }

    // ── Write — من لا يرى لا يكتُب, per row ─────────────────────────────

    public function test_pay_cannot_be_written_outside_the_field_grant(): void
    {
        $this->actingAsPayrollClerkForA1();
        $outside = Employee::factory()->create(['department_id' => $this->units['b']->id]);

        $this->patchJson("/api/hr/employees/{$outside->id}", ['salary_mode' => 2, 'agreed_salary' => '750000.00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['salary_mode', 'agreed_salary']);

        $this->patchJson("/api/hr/employees/{$outside->id}", ['national_id' => 'NID-X'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['national_id']);

        $this->assertNull($outside->fresh()->agreed_salary);
        $this->assertNull($outside->fresh()->national_id);
    }

    public function test_pay_can_be_written_inside_the_field_grant(): void
    {
        $this->actingAsPayrollClerkForA1();
        $inside = Employee::factory()->create(['department_id' => $this->units['a1a']->id]);

        $this->patchJson("/api/hr/employees/{$inside->id}", [
            'salary_mode'   => 2,
            'agreed_salary' => '750000.00',
            'national_id'   => 'NID-OK',
        ])->assertOk();

        $this->assertSame('750000.00', $inside->fresh()->agreed_salary);
    }

    public function test_a_transfer_cannot_carry_pay_across_the_edge_of_the_field_grant(): void
    {
        $this->actingAsPayrollClerkForA1();

        // Out of reach → in: the pay being overwritten belongs to someone the
        // grant does not reach yet.
        $incoming = Employee::factory()->create(['department_id' => $this->units['b']->id]);
        $this->patchJson("/api/hr/employees/{$incoming->id}", [
            'department_id' => $this->units['a1']->id,
            'agreed_salary' => '750000.00',
            'salary_mode'   => 2,
        ])->assertUnprocessable()->assertJsonValidationErrors(['agreed_salary']);

        // In → out of reach: the pay being set is for someone leaving it.
        $outgoing = Employee::factory()->create(['department_id' => $this->units['a1']->id]);
        $this->patchJson("/api/hr/employees/{$outgoing->id}", [
            'department_id' => $this->units['b']->id,
            'agreed_salary' => '750000.00',
            'salary_mode'   => 2,
        ])->assertUnprocessable()->assertJsonValidationErrors(['agreed_salary']);

        $this->assertSame($this->units['b']->id, $incoming->fresh()->department_id);
        $this->assertSame($this->units['a1']->id, $outgoing->fresh()->department_id);
    }

    /**
     * The second door: a posting copies its grade onto the employee, so the
     * assignments endpoint is gated exactly as PATCH /employees is.
     */
    public function test_a_posting_cannot_set_the_grade_outside_the_field_grant(): void
    {
        $this->actingAsPayrollClerkForA1(extraVerbs: ['hr.assignments.create']);
        $outside = Employee::factory()->create(['department_id' => $this->units['b']->id, 'organizational_grade' => 2]);
        $inside = Employee::factory()->create(['department_id' => $this->units['a1']->id, 'organizational_grade' => 2]);

        $posting = ['from_date' => now()->toDateString(), 'reason' => AssignmentReason::Promotion->value];

        $this->postJson("/api/hr/employees/{$outside->id}/assignments", $posting + ['organizational_grade' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors(['organizational_grade']);
        $this->postJson("/api/hr/employees/{$outside->id}/assignments", $posting + ['organizational_grade' => null])
            ->assertUnprocessable()->assertJsonValidationErrors(['organizational_grade']);

        // Moving someone out of reach cannot carry a grade with them either.
        $this->postJson("/api/hr/employees/{$inside->id}/assignments", $posting + [
            'department_id'        => $this->units['b']->id,
            'organizational_grade' => 5,
        ])->assertUnprocessable()->assertJsonValidationErrors(['organizational_grade']);

        $this->assertSame(2, $outside->fresh()->organizational_grade);
        $this->assertSame(2, $inside->fresh()->organizational_grade);

        // Without a grade the posting goes through: the grade carries forward.
        $this->postJson("/api/hr/employees/{$outside->id}/assignments", $posting)->assertCreated();
        $this->assertSame(2, $outside->fresh()->organizational_grade);

        // Inside the grant, the grade is written.
        $this->postJson("/api/hr/employees/{$inside->id}/assignments", $posting + ['organizational_grade' => 5])->assertCreated();
        $this->assertSame(5, $inside->fresh()->organizational_grade);
    }

    public function test_a_create_may_set_pay_only_inside_the_field_grant(): void
    {
        $this->actingAsPayrollClerkForA1();

        $payload = [
            'employee_number' => $this->nextKoboNumber(),
            'first_name' => 'جديد', 'middle_name' => 'أحمد', 'last_name' => 'موظف',
            'gender'     => Gender::Male->value,
            'salary_mode' => 2, 'agreed_salary' => '600000.00',
        ];

        $this->postJson('/api/hr/employees', $payload + ['department_id' => $this->units['b']->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['agreed_salary']);

        $this->postJson('/api/hr/employees', $payload + ['department_id' => $this->units['a1']->id])
            ->assertCreated()
            ->assertJsonPath('data.agreed_salary', '600000.00');
    }

    // ── The audit trail is not scoped, so it needs a company-wide grant ──

    public function test_a_section_scoped_pay_grant_does_not_read_pay_history_from_the_trail(): void
    {
        $this->actingAsPayrollClerkForA1();

        $id = $this->auditRowAbout($this->paidEmployeeIn('b'));

        $data = $this->getJson("/api/auth/audit-logs/{$id}")->assertOk()->json('data');

        $this->assertArrayNotHasKey('old_values', $data);
        $this->assertArrayNotHasKey('new_values', $data);
    }

    public function test_a_company_wide_pay_grant_still_reads_the_trail(): void
    {
        $this->actingAsPayrollClerkForA1(fieldScope: ScopeType::Company);

        $id = $this->auditRowAbout($this->paidEmployeeIn('b'));

        $this->getJson("/api/auth/audit-logs/{$id}")
            ->assertOk()
            ->assertJsonPath('data.new_values.agreed_salary', '750000.00');
    }

    private function auditRowAbout(Employee $employee): int
    {
        return DB::table('audit_logs')->insertGetId([
            'event'          => 'updated',
            'auditable_type' => 'employee',
            'auditable_id'   => $employee->id,
            'old_values'     => json_encode(['agreed_salary' => '500000.00']),
            'new_values'     => json_encode(['agreed_salary' => '750000.00']),
            'ip_address'     => '127.0.0.1',
            'user_agent'     => 'PHPUnit',
            'request_id'     => 'req-scoped-fields',
            'created_at'     => now(),
        ]);
    }
}
