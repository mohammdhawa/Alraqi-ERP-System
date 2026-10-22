<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Modules\Auth\Enums\ScopeType;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeaveRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Leave\Concerns\LeaveFixtures;
use Tests\TestCase;

/**
 * HR's balances and the employee's own — legacy's LeaveBalanceManagementTest
 * on ERP routes (LEAVE_BUILD.md §3). Row-scoped through the employee's
 * current unit (D6). This is also where HR enters each person's opening
 * adjustment at their switch date (Q6).
 */
class LeaveBalanceManagementTest extends TestCase
{
    use LeaveFixtures;
    use RefreshDatabase;

    private const UPDATE = ['leave.balances.update'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLeaveTree();
    }

    private function setBalance(Employee $employee, array $body, int $year = 2026): TestResponse
    {
        return $this->putJson("/api/leave/balances/{$employee->id}/{$year}", $body);
    }

    private function approvedAnnual(Employee $employee, int $days, string $start = '2026-03-01'): void
    {
        LeaveRequest::factory()->forType('annual')->approved()->create([
            'employee_id' => $employee->id, 'start_date' => $start, 'end_date' => $start, 'requested_days' => $days,
        ]);
    }

    // ── Setting an allocation ────────────────────────────────────────────

    public function test_hr_sets_an_allocation(): void
    {
        $hr = $this->actingAsScopedUser(self::UPDATE, ScopeType::Company);

        $this->setBalance($this->people['staff'], ['allocated_days' => 20, 'adjustment_days' => 2, 'note' => 'رصيد افتتاحي'])
            ->assertOk()
            ->assertJsonPath('message', 'تم تحديث رصيد الإجازات.')
            ->assertJsonPath('data.employee.id', $this->people['staff']->id)
            ->assertJsonPath('data.year', 2026)
            ->assertJsonPath('data.allocated_days', 22)
            ->assertJsonPath('data.adjustment_days', 2)
            ->assertJsonPath('data.remaining_days', 22)
            ->assertJsonPath('data.is_custom', true)
            ->assertJsonPath('data.note', 'رصيد افتتاحي');

        $this->assertSame($hr->id, (int) LeaveBalance::sole()->updated_by);
    }

    public function test_updating_twice_keeps_one_row(): void
    {
        $this->actingAsScopedUser(self::UPDATE, ScopeType::Company);

        $this->setBalance($this->people['staff'], ['allocated_days' => 20])->assertOk();
        $this->setBalance($this->people['staff'], ['allocated_days' => 18])->assertOk()->assertJsonPath('data.allocated_days', 18);

        $this->assertSame(1, LeaveBalance::count());
        $this->assertSame(0, LeaveBalance::sole()->adjustment_days, 'an omitted adjustment is zero, as legacy');
    }

    public function test_it_cannot_go_below_days_already_used(): void
    {
        $this->actingAsScopedUser(self::UPDATE, ScopeType::Company);
        $this->approvedAnnual($this->people['staff'], 5);

        $this->setBalance($this->people['staff'], ['allocated_days' => 3])
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن أن يقل الرصيد عن الأيام المعتمدة لهذه السنة (5 يوم).');
        $this->setBalance($this->people['staff'], ['allocated_days' => 3, 'adjustment_days' => 2])->assertOk();
    }

    public function test_the_body_and_the_year_are_validated(): void
    {
        $this->actingAsScopedUser(self::UPDATE, ScopeType::Company);
        $staff = $this->people['staff'];

        $this->setBalance($staff, [])->assertStatus(422)->assertJsonValidationErrors('allocated_days');
        $this->setBalance($staff, ['allocated_days' => 366])->assertStatus(422)->assertJsonValidationErrors('allocated_days');
        $this->setBalance($staff, ['allocated_days' => -1])->assertStatus(422)->assertJsonValidationErrors('allocated_days');
        $this->setBalance($staff, ['allocated_days' => 10, 'adjustment_days' => -366])->assertStatus(422)->assertJsonValidationErrors('adjustment_days');
        $this->setBalance($staff, ['allocated_days' => 10, 'note' => str_repeat('ن', 256)])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->setBalance($staff, ['allocated_days' => 10], 1999)->assertNotFound();

        $this->assertSame(0, LeaveBalance::count());
    }

    // ── Who may set it ───────────────────────────────────────────────────

    public function test_a_unit_grant_reaches_its_subtree_and_nothing_else(): void
    {
        $this->actingAsScopedUser(self::UPDATE, ScopeType::Unit, $this->units['a']->id);

        $this->setBalance($this->people['bStaff'], ['allocated_days' => 20])
            ->assertForbidden()
            ->assertJsonPath('message', 'هذا الموظف خارج نطاق صلاحياتك.');
        $this->setBalance($this->people['staff'], ['allocated_days' => 20])->assertOk();
    }

    public function test_without_the_permission_it_is_refused(): void
    {
        $this->actingAsScopedUser(['leave.balances.view'], ScopeType::Company);

        // Refused the verb — the plain refusal, not the out-of-reach one.
        $this->setBalance($this->people['staff'], ['allocated_days' => 20])
            ->assertForbidden()
            ->assertJsonPath('message', 'ليس لديك الصلاحيات الكافية لتنفيذ هذا الإجراء.');
        $this->assertSame(0, LeaveBalance::count());
    }

    // ── HR's list ────────────────────────────────────────────────────────

    public function test_the_list_reports_allocated_used_remaining_and_whether_custom(): void
    {
        $this->actingAsScopedUser(['leave.balances.view'], ScopeType::Company);
        LeaveBalance::create(['employee_id' => $this->people['staff']->id, 'year' => 2026, 'allocated_days' => 20, 'adjustment_days' => 0]);
        $this->approvedAnnual($this->people['staff'], 4);

        $response = $this->getJson('/api/leave/balances?year=2026&employee_ids='.$this->people['staff']->id.','.$this->people['bStaff']->id)
            ->assertOk()
            ->assertJsonPath('year', 2026)
            ->assertJsonPath('default_days', 15)
            ->assertJsonPath('meta.total', 2);

        $rows = collect($response->json('data'))->keyBy('employee.id');
        $this->assertSame([20, 4, 16, true], [
            $rows[$this->people['staff']->id]['allocated_days'],
            $rows[$this->people['staff']->id]['used_days'],
            $rows[$this->people['staff']->id]['remaining_days'],
            $rows[$this->people['staff']->id]['is_custom'],
        ]);
        $this->assertSame([15, 0, 15, false], [
            $rows[$this->people['bStaff']->id]['allocated_days'],
            $rows[$this->people['bStaff']->id]['used_days'],
            $rows[$this->people['bStaff']->id]['remaining_days'],
            $rows[$this->people['bStaff']->id]['is_custom'],
        ]);
        $this->assertSame('إدارة ب', $rows[$this->people['bStaff']->id]['department']['name']);
    }

    public function test_the_list_is_narrowed_to_the_grants_reach(): void
    {
        $this->actingAsScopedUser(['leave.balances.view'], ScopeType::Unit, $this->units['a']->id);

        $ids = collect($this->getJson('/api/leave/balances')->assertOk()->json('data'))->pluck('employee.id')->sort()->values()->all();

        $this->assertSame(collect([$this->people['headA']->id, $this->people['staff']->id])->sort()->values()->all(), $ids);
    }

    public function test_the_list_filters_and_sorts(): void
    {
        $this->actingAsScopedUser(['leave.balances.view'], ScopeType::Company);

        $this->getJson('/api/leave/balances?department_id='.$this->units['hr']->id)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/leave/balances?search='.$this->people['bStaff']->employee_number)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/leave/balances?sort=-employee_number')->assertOk();
        $this->getJson('/api/leave/balances?sort=salary')->assertStatus(422)->assertJsonValidationErrors('sort');
    }

    public function test_the_list_needs_its_permission(): void
    {
        $this->actingAsScopedUser(self::UPDATE, ScopeType::Company);

        $this->getJson('/api/leave/balances')->assertForbidden();
    }

    // ── The employee's own ───────────────────────────────────────────────

    public function test_the_employee_reads_their_own_balance(): void
    {
        LeaveBalance::create(['employee_id' => $this->people['staff']->id, 'year' => 2025, 'allocated_days' => 30, 'adjustment_days' => 0]);
        $this->approvedAnnual($this->people['staff'], 3);
        $this->actingAsPerson($this->people['staff']);

        $this->getJson('/api/me/leave-balance?year=2026')
            ->assertOk()
            ->assertJsonPath('data.year', 2026)
            ->assertJsonPath('data.allocated_days', 15)
            ->assertJsonPath('data.used_days', 3)
            ->assertJsonPath('data.remaining_days', 12);

        $this->getJson('/api/me/leave-balance?year=2025')->assertOk()->assertJsonPath('data.allocated_days', 30);
        $this->getJson('/api/me/leave-balance?year=1999')->assertStatus(422)->assertJsonValidationErrors('year');
    }

    public function test_an_account_with_no_employee_has_no_balance(): void
    {
        $this->actingAsRolelessUser();

        $this->getJson('/api/me/leave-balance')
            ->assertForbidden()
            ->assertJsonPath('message', 'لا يوجد موظف مرتبط بهذا الحساب.');
    }
}
