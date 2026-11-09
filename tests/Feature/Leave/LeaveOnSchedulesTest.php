<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Scheduling\Models\WorkSchedule;
use App\Modules\Scheduling\Services\ScheduleAssigner;
use Database\Seeders\SchedulingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Leave\Concerns\LeaveFixtures;
use Tests\TestCase;

/**
 * Work shifts plan Task 12: a leave charges the person's scheduled days only
 * (decision round S9). 2026-11-14 is a Saturday, 11-20 a Friday.
 */
class LeaveOnSchedulesTest extends TestCase
{
    use LeaveFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-13 10:00:00');
        $this->seed(SchedulingSeeder::class);
        $this->buildLeaveTree();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function assign(Employee $employee, string $code, string $from, ?string $cycleStart = null): void
    {
        app(ScheduleAssigner::class)->assign($employee, WorkSchedule::where('code', $code)->sole(), $from, $cycleStart, User::factory()->create());
    }

    private function file(Employee $employee, string $start, string $end): TestResponse
    {
        $this->actingAsPerson($employee);

        return $this->post('/api/me/leave-requests', [
            'leave_type_id' => $this->type('annual')->id,
            'start_date'    => $start,
            'end_date'      => $end,
        ], ['Accept' => 'application/json']);
    }

    public function test_a_guards_leave_over_one_cycle_charges_three_days(): void
    {
        $guard = $this->people['staff'];
        $this->assign($guard, 'GUARD', '2026-11-14', '2026-11-14');

        $this->file($guard, '2026-11-14', '2026-11-19')->assertCreated()
            ->assertJsonPath('data.leave_request.requested_days', 3);
    }

    public function test_an_office_week_charges_six_days(): void
    {
        $office = $this->people['staff'];
        $this->assign($office, 'OFFICE', '2026-11-14');

        $this->file($office, '2026-11-14', '2026-11-20')->assertCreated()
            ->assertJsonPath('data.leave_request.requested_days', 6);
    }

    public function test_a_leave_on_a_friday_alone_is_refused(): void
    {
        $office = $this->people['staff'];
        $this->assign($office, 'OFFICE', '2026-11-14');

        $this->file($office, '2026-11-20', '2026-11-20')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'الفترة المحددة لا تتضمن أيام عمل.');
    }

    public function test_a_leave_across_an_assignment_boundary_counts_each_date_under_its_own(): void
    {
        $employee = $this->people['staff'];
        $this->assign($employee, 'OFFICE', '2026-11-14');
        $this->assign($employee, 'GUARD', '2026-11-20', '2026-11-20');

        // OFFICE: 11-18, 11-19 → 2; GUARD from 11-20: 11-20, 21, 22 → 3; 11-23 off.
        $this->file($employee, '2026-11-18', '2026-11-23')->assertCreated()
            ->assertJsonPath('data.leave_request.requested_days', 5);
    }

    public function test_an_approved_leave_keeps_its_snapshot_after_a_schedule_change(): void
    {
        $employee = $this->people['staff'];
        $this->assign($employee, 'OFFICE', '2026-11-14');
        $leaveId = $this->file($employee, '2026-11-21', '2026-11-26')->assertCreated()->json('data.leave_request.id');

        $this->assign($employee, 'GUARD', '2026-11-21', '2026-11-21');

        $this->assertSame(6, (int) LeaveRequest::query()->findOrFail($leaveId)->requested_days, 'Saturday to Thursday under OFFICE; GUARD would give 3');
    }

    public function test_the_on_leave_rows_carry_their_own_working_day(): void
    {
        $guard = $this->people['staff'];
        $this->assign($guard, 'GUARD', '2026-11-14', '2026-11-14');
        $office = $this->people['bStaff'];
        $this->assign($office, 'OFFICE', '2026-11-14');
        foreach ([$guard, $office] as $employee) {
            LeaveRequest::factory()->forType('annual')->approved()->create(['employee_id' => $employee->id, 'start_date' => '2026-11-17', 'end_date' => '2026-11-17', 'requested_days' => 1]);
        }
        $this->actingAsAdmin();

        $rows = collect($this->getJson('/api/leave/on-leave?date=2026-11-17')->assertOk()->json('data'))->keyBy('employee.id');

        $this->assertFalse($rows[$guard->id]['working_day'], 'a guard rest day');
        $this->assertTrue($rows[$office->id]['working_day']);
    }

    public function test_the_register_counts_scheduled_days(): void
    {
        $guard = $this->people['staff'];
        $this->assign($guard, 'GUARD', '2026-11-14', '2026-11-14');
        LeaveRequest::factory()->forType('annual')->approved()->create(['employee_id' => $guard->id, 'start_date' => '2026-11-14', 'end_date' => '2026-11-19', 'requested_days' => 3]);
        $this->actingAsAdmin();

        $this->getJson('/api/leave/requests?from=2026-11-01&to=2026-11-30')->assertOk()
            ->assertJsonPath('data.0.days_in_range', 3)
            ->assertJsonPath('data.0.calendar_days_in_range', 6);
    }
}
