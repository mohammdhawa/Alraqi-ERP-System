<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Services\LeaveBalances;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The balance — legacy's User::annualLeaveDays / usedLeaveDays /
 * remainingLeaveDays / nonDeductingLeaveDays and balancePayload()
 * (LEAVE_BUILD.md §3). Computed, never stored.
 */
class LeaveBalanceCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->employee = Employee::factory()->create();
    }

    private function figures(int $year = 2026): array
    {
        return app(LeaveBalances::class)->for($this->employee->id, $year)->toArray();
    }

    private function leave(string $type, LeaveStatus $status, string $start, string $end, int $days, array $attributes = []): LeaveRequest
    {
        return LeaveRequest::factory()->forType($type)->create([
            'employee_id'    => $this->employee->id,
            'status'         => $status,
            'start_date'     => $start,
            'end_date'       => $end,
            'requested_days' => $days,
        ] + $attributes);
    }

    public function test_with_no_row_the_company_default_applies(): void
    {
        $this->assertSame([
            'year'               => 2026,
            'allocated_days'     => 15,
            'adjustment_days'    => 0,
            'used_days'          => 0,
            'remaining_days'     => 15,
            'over_balance_days'  => 0,
            'non_deducting_days' => 0,
            'is_custom'          => false,
            'note'               => null,
        ], $this->figures());
    }

    public function test_a_personal_row_adds_its_adjustment(): void
    {
        LeaveBalance::create(['employee_id' => $this->employee->id, 'year' => 2026, 'allocated_days' => 20, 'adjustment_days' => -3, 'note' => 'رصيد افتتاحي']);

        $figures = $this->figures();
        $this->assertSame(17, $figures['allocated_days']);
        $this->assertSame(-3, $figures['adjustment_days']);
        $this->assertSame(17, $figures['remaining_days']);
        $this->assertTrue($figures['is_custom']);
        $this->assertSame('رصيد افتتاحي', $figures['note']);
    }

    public function test_only_approved_deducting_leave_is_used(): void
    {
        $this->leave('annual', LeaveStatus::Approved, '2026-03-01', '2026-03-03', 3);
        $this->leave('annual', LeaveStatus::Pending, '2026-04-01', '2026-04-04', 4);
        $this->leave('annual', LeaveStatus::Rejected, '2026-05-01', '2026-05-05', 5);
        $this->leave('annual', LeaveStatus::Cancelled, '2026-06-01', '2026-06-06', 6);
        $this->leave('sick', LeaveStatus::Approved, '2026-07-01', '2026-07-02', 2);

        $figures = $this->figures();
        $this->assertSame(3, $figures['used_days']);
        $this->assertSame(12, $figures['remaining_days']);
        $this->assertSame(2, $figures['non_deducting_days'], 'shown beside, never counted');
        $this->assertSame(12, app(LeaveBalances::class)->remaining($this->employee->id, 2026));
    }

    public function test_an_approved_deducting_excuse_is_used(): void
    {
        LeaveRequest::factory()->forType('annual')->excuse()->create([
            'employee_id' => $this->employee->id, 'start_date' => '2026-02-10', 'end_date' => '2026-02-11', 'requested_days' => 2,
        ]);

        $this->assertSame(2, $this->figures()['used_days']);
    }

    public function test_the_snapshot_decides_not_the_type(): void
    {
        // An annual request filed while the type did not deduct stays free.
        $this->leave('annual', LeaveStatus::Approved, '2026-03-01', '2026-03-02', 2, ['deducts_balance' => false]);

        $this->assertSame(0, $this->figures()['used_days']);
        $this->assertSame(2, $this->figures()['non_deducting_days']);
    }

    public function test_a_leave_counts_wholly_in_the_year_it_starts(): void
    {
        $this->leave('annual', LeaveStatus::Approved, '2026-12-30', '2027-01-02', 4);

        $this->assertSame(4, $this->figures(2026)['used_days']);
        $this->assertSame(0, $this->figures(2027)['used_days']);
        $this->assertSame(15, $this->figures(2027)['remaining_days']);
    }

    public function test_a_forced_overdraft_shows_as_over_balance(): void
    {
        LeaveBalance::create(['employee_id' => $this->employee->id, 'year' => 2026, 'allocated_days' => 2, 'adjustment_days' => 0]);
        $this->leave('annual', LeaveStatus::Approved, '2026-03-01', '2026-03-05', 5);

        $figures = $this->figures();
        $this->assertSame(0, $figures['remaining_days']);
        $this->assertSame(3, $figures['over_balance_days']);
    }

    public function test_a_row_belongs_to_its_year(): void
    {
        LeaveBalance::create(['employee_id' => $this->employee->id, 'year' => 2025, 'allocated_days' => 30, 'adjustment_days' => 0]);

        $this->assertSame(15, $this->figures(2026)['allocated_days']);
        $this->assertSame(30, $this->figures(2025)['allocated_days']);
    }

    public function test_an_adjustment_cannot_take_the_allocation_below_zero(): void
    {
        LeaveBalance::create(['employee_id' => $this->employee->id, 'year' => 2026, 'allocated_days' => 3, 'adjustment_days' => -10]);

        $this->assertSame(0, $this->figures()['allocated_days']);
    }

    public function test_many_employees_are_measured_together_and_alike(): void
    {
        $other = Employee::factory()->create();
        LeaveBalance::create(['employee_id' => $other->id, 'year' => 2026, 'allocated_days' => 10, 'adjustment_days' => 0]);
        $this->leave('annual', LeaveStatus::Approved, '2026-03-01', '2026-03-03', 3);
        LeaveRequest::factory()->forType('sick')->approved()->create(['employee_id' => $other->id, 'requested_days' => 4]);

        $many = app(LeaveBalances::class)->forMany([$this->employee->id, $other->id], 2026);

        $this->assertSame($this->figures(), $many[$this->employee->id]->toArray());
        $this->assertSame(10, $many[$other->id]->remaining);
        $this->assertSame(4, $many[$other->id]->nonDeducting);
    }
}
