<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Modules\Auth\Enums\ScopeType;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Leave\Concerns\LeaveFixtures;
use Tests\TestCase;

/**
 * GET /api/leave/on-leave — who is on approved leave on one day, legacy's
 * `/approved-leave-users` (owner, 2026-10-20, parity audit X2): approved
 * requests and HR excuses covering the day, for the employees the caller's
 * `attendance.records.view` reaches; departed employees left out (C-8).
 */
class OnLeaveRosterTest extends TestCase
{
    use LeaveFixtures;
    use RefreshDatabase;

    private const DAY = '2026-06-21';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::DAY.' 10:00:00');
        $this->buildLeaveTree();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function leave(Employee $employee, string $type, string $start, string $end, array $attributes = []): LeaveRequest
    {
        return LeaveRequest::factory()->forType($type)->approved()->create([
            'employee_id' => $employee->id, 'start_date' => $start, 'end_date' => $end,
        ] + $attributes);
    }

    private function roster(array $query = []): TestResponse
    {
        return $this->getJson('/api/leave/on-leave?'.http_build_query($query));
    }

    /** @return array<int, int> */
    private function ids(TestResponse $response): array
    {
        return array_column($response->assertOk()->json('data'), 'id');
    }

    public function test_it_lists_approved_requests_and_excuses_covering_today(): void
    {
        $annual = $this->leave($this->people['staff'], 'annual', '2026-06-20', '2026-06-22');
        $excuse = $this->leave($this->people['bStaff'], 'sick', self::DAY, self::DAY, ['origin' => 2]);
        $this->leave($this->people['headA'], 'annual', '2026-06-22', '2026-06-23');
        $this->leave($this->people['hrStaff'], 'annual', self::DAY, self::DAY, ['status' => LeaveStatus::Pending]);
        $this->actingAsScopedUser(['attendance.records.view'], ScopeType::Company);

        $response = $this->roster();

        $this->assertEqualsCanonicalizing([$annual->id, $excuse->id], $this->ids($response));
        $response->assertJsonPath('date', self::DAY)->assertJsonPath('working_day', true);
    }

    public function test_another_day_and_the_filters(): void
    {
        $annual = $this->leave($this->people['staff'], 'annual', '2026-07-01', '2026-07-02');
        $excuse = $this->leave($this->people['bStaff'], 'sick', '2026-07-01', '2026-07-01', ['origin' => 2]);
        $this->actingAsScopedUser(['attendance.records.view'], ScopeType::Company);

        $this->assertSame([], $this->ids($this->roster()));
        $this->assertEqualsCanonicalizing([$annual->id, $excuse->id], $this->ids($this->roster(['date' => '2026-07-01'])));
        $this->assertSame([$excuse->id], $this->ids($this->roster(['date' => '2026-07-01', 'is_excuse' => 1])));
        $this->assertSame([$annual->id], $this->ids($this->roster(['date' => '2026-07-01', 'leave_type_ids' => (string) $this->type('annual')->id])));
        $this->assertSame([$annual->id], $this->ids($this->roster(['date' => '2026-07-01', 'department_id' => $this->units['a']->id])));
    }

    public function test_reach_is_attendances_and_departed_employees_are_left_out(): void
    {
        $this->leave($this->people['staff'], 'annual', self::DAY, self::DAY);
        $b = $this->leave($this->people['bStaff'], 'annual', self::DAY, self::DAY);
        $gone = $this->leave($this->people['hrStaff'], 'annual', self::DAY, self::DAY);
        $this->people['hrStaff']->update(['status' => EmployeeStatus::Terminated]);

        $this->actingAsScopedUser(['attendance.records.view'], ScopeType::Unit, $this->units['b']->id);
        $this->assertSame([$b->id], $this->ids($this->roster()));

        $this->actingAsScopedUser(['attendance.records.view'], ScopeType::Company);
        $this->assertNotContains($gone->id, $this->ids($this->roster()));
    }

    public function test_it_needs_attendance_records_view_and_refuses_bad_values(): void
    {
        $this->actingAsScopedUser(['leave.requests.view'], ScopeType::Company);
        $this->roster()->assertForbidden();

        $this->actingAsScopedUser(['attendance.records.view'], ScopeType::Company);
        $this->roster(['date' => '21-06-2026'])->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->roster(['sort' => 'salary'])->assertUnprocessable()->assertJsonValidationErrors('sort');
    }
}
