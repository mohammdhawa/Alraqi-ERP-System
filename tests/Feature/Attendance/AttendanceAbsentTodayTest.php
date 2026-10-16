<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\RejectionReason;
use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Attendance\Concerns\AttendanceTree;
use Tests\Feature\Attendance\Concerns\FakesApprovedLeave;
use Tests\TestCase;

/**
 * GET /api/attendance/absent-today — who is expected and has not checked in
 * (legacy's /attendance/absent-today and its tests).
 *
 * Expected (Q7): every active employee with an active account. A refused
 * check-in is not a check-in. Approved leave excuses the day unless the
 * config allows attendance during leave.
 */
class AttendanceAbsentTodayTest extends TestCase
{
    use AttendanceTree;
    use FakesApprovedLeave;
    use RefreshDatabase;

    private const DAY = '2026-06-21';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::DAY.' 11:00:00');
        $this->buildTree();
        $this->actingAsScopedUser(['attendance.records.view'], ScopeType::Company);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @param  array<string, mixed>  $query */
    private function absent(array $query = []): TestResponse
    {
        return $this->getJson('/api/attendance/absent-today?'.http_build_query($query));
    }

    /** @return array<int, int> */
    private function ids(TestResponse $response): array
    {
        $ids = array_column(array_column($response->json('data'), 'employee'), 'id');
        sort($ids);

        return $ids;
    }

    /** @return array<int, int> */
    private function idsOf(string ...$keys): array
    {
        $ids = array_map(fn (string $key): int => $this->people[$key]->id, $keys);
        sort($ids);

        return $ids;
    }

    public function test_the_expected_who_have_not_checked_in(): void
    {
        $this->checkIn($this->people['a'], self::DAY.' 08:00:00');

        $response = $this->absent()->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertSame($this->idsOf('a1', 'b'), $this->ids($response));
        $this->assertSame(['id' => $this->units['b']->id, 'name' => 'إدارة ب'],
            collect($response->json('data'))->firstWhere('employee.id', $this->people['b']->id)['department']);
    }

    public function test_a_refused_check_in_is_not_a_check_in(): void
    {
        $this->checkIn($this->people['a'], self::DAY.' 08:00:00', ['rejected_at' => now(), 'rejection_reason' => RejectionReason::Location]);

        $this->assertSame($this->idsOf('a', 'a1', 'b'), $this->ids($this->absent()));
    }

    public function test_approved_leave_excuses_the_day_unless_attendance_during_leave_is_allowed(): void
    {
        $this->grantLeave($this->people['b'], self::DAY, self::DAY);

        $this->assertSame($this->idsOf('a', 'a1'), $this->ids($this->absent()));

        config(['attendance.allow_attendance_during_leave' => true]);
        $this->assertSame($this->idsOf('a', 'a1', 'b'), $this->ids($this->absent()));
    }

    public function test_the_suspended_and_those_without_an_active_account_are_not_expected(): void
    {
        $suspended = Employee::factory()->create(['department_id' => $this->units['a']->id, 'status' => EmployeeStatus::Suspended]);
        User::factory()->create(['employee_id' => $suspended->id]);
        $disabled = Employee::factory()->create(['department_id' => $this->units['a']->id]);
        User::factory()->inactive()->create(['employee_id' => $disabled->id]);

        $this->assertSame($this->idsOf('a', 'a1', 'b'), $this->ids($this->absent()), 'headA has no account');
    }

    public function test_a_day_off_has_no_absentees(): void
    {
        config(['attendance.working_days' => [1, 2, 3, 4, 5, 6]]);   // Sunday off

        $this->absent()->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_another_date_reach_filters_and_order(): void
    {
        $this->checkIn($this->people['a'], '2026-06-20 08:00:00');
        $this->assertSame($this->idsOf('a1', 'b'), $this->ids($this->absent(['date' => '2026-06-20'])));

        $this->assertSame($this->idsOf('a', 'a1'), $this->ids($this->absent(['department_id' => $this->units['a']->id])));

        $this->people['b']->update(['first_name' => 'Aaa']);
        $this->absent(['sort' => 'employee_name', 'per_page' => 1])
            ->assertJsonPath('data.0.employee.id', $this->people['b']->id)
            ->assertJsonPath('meta.last_page', 3);

        $this->actingAsScopedUser(['attendance.records.view'], ScopeType::Unit, $this->units['b']->id);
        $this->assertSame($this->idsOf('b'), $this->ids($this->absent()));
    }

    public function test_no_permission_is_403(): void
    {
        $this->actingAsRolelessUser();

        $this->absent()->assertForbidden();
    }
}
