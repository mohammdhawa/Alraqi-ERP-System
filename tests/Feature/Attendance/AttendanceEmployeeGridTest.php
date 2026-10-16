<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\AttendanceDayState;
use App\Modules\Attendance\Enums\AttendanceRecordStatus;
use App\Modules\Attendance\Enums\RejectionReason;
use App\Modules\Auth\Enums\ScopeType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Attendance\Concerns\AttendanceTree;
use Tests\Feature\Attendance\Concerns\FakesApprovedLeave;
use Tests\TestCase;

/**
 * GET /api/attendance/report/employee — one employee's days over a range, one
 * state per day (legacy's AttendanceEmployeeBreakdownTest, and the grid half
 * of its rejection tests).
 *
 * The state is resolved by legacy's precedence: an accepted check-in wins
 * (checked out / checked in / missing check-out), then a day off, then an
 * excuse, then leave, then a refusal, otherwise absent.
 */
class AttendanceEmployeeGridTest extends TestCase
{
    use AttendanceTree;
    use FakesApprovedLeave;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildTree();
    }

    /** @param  array<string, mixed>  $query */
    private function grid(string $key = 'a', array $query = []): TestResponse
    {
        return $this->getJson('/api/attendance/report/employee?'.http_build_query($query + [
            'employee_id' => $this->people[$key]->id,
            'from'        => '2026-06-21',
            'to'          => '2026-06-27',
        ]));
    }

    /** @return array<string, int> date => state */
    private function states(TestResponse $response): array
    {
        return array_column($response->json('data.days'), 'status', 'date');
    }

    public function test_one_state_per_day_by_legacys_precedence(): void
    {
        $a = $this->people['a'];
        $this->checkIn($a, '2026-06-21 08:00:00');
        $this->checkOut($a, '2026-06-21 16:00:00');
        $this->checkIn($a, '2026-06-22 08:00:00');
        $this->checkIn($a, '2026-06-23 08:00:00', ['status' => AttendanceRecordStatus::MissingCheckout]);
        $this->grantLeave($a, '2026-06-24', '2026-06-24');
        $this->grantLeave($a, '2026-06-25', '2026-06-25', excuse: true);
        $this->checkIn($a, '2026-06-26 08:00:00', ['rejected_at' => now(), 'rejection_reason' => RejectionReason::Selfie]);
        $this->actingAsScopedUser(['attendance.records.view'], ScopeType::Company);

        $this->assertSame([
            '2026-06-21' => AttendanceDayState::CheckedOut->value,
            '2026-06-22' => AttendanceDayState::CheckedIn->value,
            '2026-06-23' => AttendanceDayState::MissingCheckout->value,
            '2026-06-24' => AttendanceDayState::OnLeave->value,
            '2026-06-25' => AttendanceDayState::Excused->value,
            '2026-06-26' => AttendanceDayState::Rejected->value,
            '2026-06-27' => AttendanceDayState::Absent->value,
        ], $this->states($this->grid()->assertOk()));
    }

    public function test_the_summary_closes(): void
    {
        $a = $this->people['a'];
        $this->checkIn($a, '2026-06-21 08:00:00');
        $this->checkOut($a, '2026-06-21 16:00:00', ['work_hours' => 8.0]);
        $this->grantLeave($a, '2026-06-22', '2026-06-23');
        $this->grantLeave($a, '2026-06-24', '2026-06-24', excuse: true, deducts: false);
        $this->checkIn($a, '2026-06-25 08:00:00', ['rejected_at' => now(), 'rejection_reason' => RejectionReason::Other]);
        $this->actingAsScopedUser(['attendance.records.view'], ScopeType::Company);

        $response = $this->grid()->assertOk()
            ->assertJsonPath('data.employee.id', $a->id)
            ->assertJsonPath('data.working_days', 7);

        $this->assertSame([
            'expected_days'              => 7,
            'present_days'               => 1,
            'on_leave_days'              => 2,
            'excused_days'               => 1,
            'excused_deducted_days'      => 0,
            'excused_not_deducted_days'  => 1,
            'absent_days'                => 3,
            'rejected_days'              => 1,
            'off_days'                   => 0,
            'missing_checkouts'          => 0,
            'total_work_hours'           => 8,
            'total_work_hours_formatted' => '8h 0m',
            'attendance_rate'            => 14.3,
        ], $response->json('data.summary'));
    }

    public function test_a_day_carries_its_check_in_id_correction_rejection_and_excuse(): void
    {
        $a = $this->people['a'];
        $corrector = $this->actingAsScopedUser(['attendance.records.view'], ScopeType::Company);
        $in = $this->checkIn($a, '2026-06-21 08:00:00');
        $this->checkOut($a, '2026-06-21 16:00:00', ['corrected_by' => $corrector->id, 'corrected_at' => now(), 'correction_note' => 'نسي']);
        $this->checkIn($a, '2026-06-22 08:00:00', ['rejected_at' => now(), 'rejected_by' => $corrector->id, 'rejection_reason' => RejectionReason::Location]);
        $this->grantLeave($a, '2026-06-22', '2026-06-22');   // leave outranks the refusal…
        $this->grantLeave($a, '2026-06-23', '2026-06-23', excuse: true, type: 'attendance_excused', typeName: 'حضور مبرر');

        $days = collect($this->grid()->json('data.days'))->keyBy('date');

        $this->assertSame($in->id, $days['2026-06-21']['check_in_id']);
        $this->assertSame('نسي', $days['2026-06-21']['correction']['note']);
        $this->assertSame($corrector->id, $days['2026-06-21']['correction']['by']['id']);
        $this->assertSame(AttendanceDayState::OnLeave->value, $days['2026-06-22']['status']);
        $this->assertSame(RejectionReason::Location->value, $days['2026-06-22']['rejection']['reason'], '…but the refusal is still shown');
        $this->assertSame('إجازة سنوية', $days['2026-06-22']['leave_type']);
        $this->assertSame('حضور مبرر', $days['2026-06-23']['excuse']['leave_type_name']);
        $this->assertNull($days['2026-06-22']['excuse']);
    }

    public function test_a_configured_day_off_is_off_even_under_leave(): void
    {
        config(['attendance.working_days' => [1, 2, 3, 4, 5, 6]]);   // Sunday off
        $this->grantLeave($this->people['a'], '2026-06-21', '2026-06-22');
        $this->actingAsScopedUser(['attendance.records.view'], ScopeType::Company);

        $response = $this->grid();

        $this->assertSame(AttendanceDayState::Off->value, $this->states($response)['2026-06-21']);
        $this->assertSame(1, $response->json('data.summary.on_leave_days'));
        $this->assertSame(6, $response->json('data.working_days'));
    }

    public function test_day_filters_narrow_the_grid_not_the_summary(): void
    {
        $this->checkIn($this->people['a'], '2026-06-21 08:00:00');
        $this->actingAsScopedUser(['attendance.records.view'], ScopeType::Company);

        $response = $this->grid(query: ['status' => AttendanceDayState::Absent->value, 'sort' => '-date']);

        $this->assertCount(6, $response->json('data.days'));
        $this->assertSame('2026-06-27', $response->json('data.days.0.date'));
        $this->assertSame(1, $response->json('data.summary.present_days'));
    }

    public function test_out_of_reach_is_403_and_parameters_are_required(): void
    {
        $this->actingAsScopedUser(['attendance.records.view'], ScopeType::Unit, $this->units['a']->id);

        $this->grid('a1')->assertOk();
        $this->grid('b')->assertForbidden()->assertJsonPath('success', false);
        $this->getJson('/api/attendance/report/employee')->assertStatus(422)->assertJsonValidationErrors(['employee_id', 'from', 'to']);
        $this->getJson('/api/attendance/report/employee?employee_id=999999&from=2026-06-01&to=2026-06-02')
            ->assertStatus(422)->assertJsonValidationErrors('employee_id');
    }

    public function test_the_month_shorthand_and_the_cap(): void
    {
        $this->actingAsScopedUser(['attendance.records.view'], ScopeType::Company);

        $this->getJson("/api/attendance/report/employee?employee_id={$this->people['a']->id}&month=6&year=2026")
            ->assertOk()->assertJsonCount(30, 'data.days');
        $this->getJson("/api/attendance/report/employee?employee_id={$this->people['a']->id}&from=2026-01-01&to=2026-06-30")
            ->assertStatus(422)->assertJsonValidationErrors('to');
    }
}
