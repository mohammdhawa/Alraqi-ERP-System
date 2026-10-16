<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\AttendanceRecordStatus;
use App\Modules\Attendance\Enums\RejectionReason;
use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Attendance\Concerns\AttendanceTree;
use Tests\Feature\Attendance\Concerns\FakesApprovedLeave;
use Tests\TestCase;

/**
 * GET /api/attendance/report/monthly — one summary row per employee over a
 * range, plus totals (legacy's AttendanceMonthlyReportTest, and the monthly
 * half of its rejection and working-calendar tests).
 *
 * Every day is a working day (Q8), so June 2026 expects 30. The arithmetic
 * always closes: expected = present + on leave + excused + absent.
 *
 * WHO HAS A ROW (Q7): every ACTIVE employee with an ACTIVE account — the
 * people expected to record attendance. Legacy's `attendance_check` flag is
 * gone; a suspended employee, or one with no account, has no row.
 */
class AttendanceMonthlyReportTest extends TestCase
{
    use AttendanceTree;
    use FakesApprovedLeave;
    use RefreshDatabase;

    private const JUNE = 30;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildTree();
        $this->actingAsScopedUser(['attendance.records.view'], ScopeType::Company);
    }

    /** @param  array<string, mixed>  $query */
    private function report(array $query = []): TestResponse
    {
        return $this->getJson('/api/attendance/report/monthly?'.http_build_query($query + ['from' => '2026-06-01', 'to' => '2026-06-30']));
    }

    /** @return array<string, mixed> */
    private function rowOf(TestResponse $response, string $key): array
    {
        return collect($response->json('data.rows'))->firstWhere('employee.id', $this->people[$key]->id);
    }

    public function test_present_leave_absent_hours_and_rate(): void
    {
        $this->checkIn($this->people['a'], '2026-06-21 08:00:00');
        $this->checkOut($this->people['a'], '2026-06-21 16:00:00', ['work_hours' => 8.0]);
        $this->checkIn($this->people['a'], '2026-06-22 08:30:00');
        $this->checkOut($this->people['a'], '2026-06-22 16:00:00', ['work_hours' => 7.5]);
        $this->grantLeave($this->people['a'], '2026-06-23', '2026-06-24');

        $response = $this->report()->assertOk()->assertJsonPath('data.working_days', self::JUNE);
        $row = $this->rowOf($response, 'a');

        $this->assertSame(self::JUNE, $row['expected_days']);
        $this->assertSame(2, $row['present_days']);
        $this->assertSame(2, $row['on_leave_days']);
        $this->assertSame(26, $row['absent_days']);
        $this->assertSame(15.5, $row['total_work_hours']);
        $this->assertSame('15h 30m', $row['total_work_hours_formatted']);
        $this->assertSame(6.7, $row['attendance_rate']);
        $this->assertSame(['id' => $this->units['a']->id, 'name' => 'إدارة أ'], $row['department']);
    }

    public function test_a_missing_check_out_is_still_a_present_day(): void
    {
        $this->checkIn($this->people['a'], '2026-06-21 08:00:00', ['status' => AttendanceRecordStatus::MissingCheckout]);

        $row = $this->rowOf($this->report(), 'a');

        $this->assertSame(1, $row['missing_checkouts']);
        $this->assertSame(1, $row['present_days']);
    }

    public function test_leave_is_clipped_to_the_window(): void
    {
        $this->grantLeave($this->people['a'], '2026-05-28', '2026-06-02');

        $this->assertSame(2, $this->rowOf($this->report(), 'a')['on_leave_days']);
    }

    public function test_excuses_count_apart_and_split_by_balance(): void
    {
        $this->grantLeave($this->people['a'], '2026-06-01', '2026-06-02', excuse: true, deducts: true);
        $this->grantLeave($this->people['a'], '2026-06-03', '2026-06-03', excuse: true, deducts: false);

        $row = $this->rowOf($this->report(), 'a');

        $this->assertSame(3, $row['excused_days']);
        $this->assertSame(2, $row['excused_deducted_days']);
        $this->assertSame(1, $row['excused_not_deducted_days']);
        $this->assertSame(0, $row['on_leave_days']);
        $this->assertSame(27, $row['absent_days']);
    }

    public function test_working_through_leave_is_counted_once(): void
    {
        $this->grantLeave($this->people['a'], '2026-06-21', '2026-06-22');
        $this->checkIn($this->people['a'], '2026-06-21 08:00:00');

        $row = $this->rowOf($this->report(), 'a');

        $this->assertSame(1, $row['present_days']);
        $this->assertSame(1, $row['on_leave_days']);
        $this->assertSame(self::JUNE, $row['present_days'] + $row['on_leave_days'] + $row['excused_days'] + $row['absent_days']);
    }

    public function test_a_refusal_not_put_right_is_one_absent_day_noted_as_rejected(): void
    {
        $refused = ['rejected_at' => now(), 'rejection_reason' => RejectionReason::Location];
        $this->checkIn($this->people['a'], '2026-06-21 08:00:00', $refused);           // not put right
        $this->checkIn($this->people['a'], '2026-06-22 08:00:00', $refused);
        $this->checkIn($this->people['a'], '2026-06-22 09:00:00');                     // put right
        $this->checkIn($this->people['a'], '2026-06-23 08:00:00', $refused);
        $this->grantLeave($this->people['a'], '2026-06-23', '2026-06-23');            // leave explains it

        $row = $this->rowOf($this->report(), 'a');

        $this->assertSame(1, $row['rejected_days']);
        $this->assertSame(1, $row['present_days']);
        $this->assertSame(28, $row['absent_days'], 'the refused day is inside absent_days, not beside it');
    }

    public function test_totals_sum_the_rows(): void
    {
        $this->checkIn($this->people['a'], '2026-06-21 08:00:00');
        $this->checkIn($this->people['b'], '2026-06-22 08:00:00');

        $totals = $this->report()->json('data.totals');

        $this->assertSame(3, $totals['employees']);
        $this->assertSame(self::JUNE * 3, $totals['expected_days']);
        $this->assertSame(2, $totals['present_days']);
    }

    public function test_only_active_employees_with_an_active_account_have_a_row(): void
    {
        $suspended = Employee::factory()->create(['department_id' => $this->units['a']->id, 'status' => EmployeeStatus::Suspended]);
        User::factory()->create(['employee_id' => $suspended->id]);
        $disabled = Employee::factory()->create(['department_id' => $this->units['a']->id]);
        User::factory()->inactive()->create(['employee_id' => $disabled->id]);

        $ids = array_column(array_column($this->report()->json('data.rows'), 'employee'), 'id');

        $this->assertEqualsCanonicalizing([$this->people['a']->id, $this->people['a1']->id, $this->people['b']->id], $ids, 'headA has no account');
    }

    public function test_a_unit_filter_and_a_unit_grant_narrow_the_rows(): void
    {
        $this->assertCount(2, $this->report(['department_id' => $this->units['a']->id])->json('data.rows'));

        $this->actingAsScopedUser(['attendance.records.view'], ScopeType::Unit, $this->units['b']->id);
        $this->assertCount(1, $this->report()->json('data.rows'));
    }

    public function test_row_filters_recompute_the_totals_and_rows_sort(): void
    {
        foreach (range(1, 30) as $day) {
            $this->checkIn($this->people['a'], sprintf('2026-06-%02d 08:00:00', $day));
        }
        $this->checkIn($this->people['b'], '2026-06-21 08:00:00', ['status' => AttendanceRecordStatus::MissingCheckout]);

        $response = $this->report(['has_absences' => 1, 'sort' => '-present_days']);
        $this->assertSame([$this->people['b']->id, $this->people['a1']->id], array_column(array_column($response->json('data.rows'), 'employee'), 'id'));
        $this->assertSame(2, $response->json('data.totals.employees'));

        $this->assertCount(1, $this->report(['has_missing_checkouts' => 1])->json('data.rows'));
    }

    public function test_the_month_shorthand(): void
    {
        $this->getJson('/api/attendance/report/monthly?month=2&year=2026')
            ->assertOk()
            ->assertJsonPath('data.from', '2026-02-01')
            ->assertJsonPath('data.to', '2026-02-28')
            ->assertJsonPath('data.working_days', 28);
    }

    public function test_the_range_is_required_and_capped(): void
    {
        $this->getJson('/api/attendance/report/monthly')->assertStatus(422)->assertJsonValidationErrors(['from', 'to']);
        $this->getJson('/api/attendance/report/monthly?from=2026-01-01&to=2026-06-30')
            ->assertStatus(422)->assertJsonValidationErrors('to');
    }

    public function test_no_permission_is_403(): void
    {
        $this->actingAsRolelessUser();

        $this->report()->assertForbidden();
    }
}
