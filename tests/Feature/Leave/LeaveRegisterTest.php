<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Modules\Auth\Enums\ScopeType;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Enums\LeaveOrigin;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Leave\Concerns\LeaveFixtures;
use Tests\TestCase;

/**
 * GET /api/leave/requests — HR's leave register, legacy's LeaveRegisterTest
 * (LEAVE_BUILD.md §6): every leave in reach overlapping a window, each row
 * with the days that fall inside it, and a summary over the WHOLE filtered
 * set, never the page. Approved rows (excuses included) by default.
 */
class LeaveRegisterTest extends TestCase
{
    use LeaveFixtures;
    use RefreshDatabase;

    private const VIEW = ['leave.requests.view'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLeaveTree();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function leave(Employee $employee, string $start, string $end, int $days, string $type = 'annual', LeaveStatus $status = LeaveStatus::Approved, array $attributes = []): LeaveRequest
    {
        return LeaveRequest::factory()->forType($type)->create([
            'employee_id' => $employee->id, 'start_date' => $start, 'end_date' => $end, 'requested_days' => $days, 'status' => $status,
        ] + $attributes);
    }

    private function register(string $query = 'from=2026-06-01&to=2026-06-30'): TestResponse
    {
        return $this->getJson('/api/leave/requests?'.$query);
    }

    private function ids(TestResponse $response): array
    {
        return array_column($response->assertOk()->json('data'), 'id');
    }

    // ── Who reads it ─────────────────────────────────────────────────────

    public function test_a_company_grant_reads_every_unit(): void
    {
        $a = $this->leave($this->people['staff'], '2026-06-10', '2026-06-11', 2);
        $b = $this->leave($this->people['bStaff'], '2026-06-12', '2026-06-12', 1);
        $this->actingAsScopedUser(self::VIEW, ScopeType::Company);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->ids($this->register()));
    }

    public function test_a_unit_grant_reads_its_reach_only(): void
    {
        $a = $this->leave($this->people['staff'], '2026-06-10', '2026-06-11', 2);
        $this->leave($this->people['bStaff'], '2026-06-12', '2026-06-12', 1);
        $this->actingAsScopedUser(self::VIEW, ScopeType::Unit, $this->units['a']->id);

        $this->assertSame([$a->id], $this->ids($this->register()));
        $this->register()->assertJsonPath('summary.requests', 1);
    }

    public function test_without_the_permission_it_is_refused(): void
    {
        $this->actingAsPerson($this->people['staff']);

        $this->register()->assertForbidden();
    }

    // ── The window ───────────────────────────────────────────────────────

    public function test_a_leave_crossing_the_window_is_listed_and_clipped(): void
    {
        $leave = $this->leave($this->people['staff'], '2026-05-29', '2026-06-03', 6);
        $this->actingAsScopedUser(self::VIEW, ScopeType::Company);

        $this->register()
            ->assertOk()
            ->assertJsonPath('from', '2026-06-01')
            ->assertJsonPath('to', '2026-06-30')
            ->assertJsonPath('data.0.id', $leave->id)
            ->assertJsonPath('data.0.requested_days', 6)
            ->assertJsonPath('data.0.days_in_range', 3)
            ->assertJsonPath('data.0.calendar_days_in_range', 3);
    }

    public function test_days_in_range_are_working_days(): void
    {
        config(['attendance.working_days' => [0, 1, 2, 3, 4]]);
        // Thursday 25 June → Sunday 28: two working days, four calendar days.
        $this->leave($this->people['staff'], '2026-06-25', '2026-06-28', 2);
        $this->actingAsScopedUser(self::VIEW, ScopeType::Company);

        $this->register()->assertJsonPath('data.0.days_in_range', 2)->assertJsonPath('data.0.calendar_days_in_range', 4);
    }

    public function test_a_leave_outside_the_window_is_absent(): void
    {
        $this->leave($this->people['staff'], '2026-07-01', '2026-07-02', 2);
        $this->actingAsScopedUser(self::VIEW, ScopeType::Company);

        $this->register()->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_month_and_year_expand_to_that_month(): void
    {
        $this->leave($this->people['staff'], '2026-02-10', '2026-02-10', 1);
        $this->actingAsScopedUser(self::VIEW, ScopeType::Company);

        $this->register('month=2&year=2026')->assertOk()->assertJsonPath('from', '2026-02-01')->assertJsonPath('to', '2026-02-28')->assertJsonCount(1, 'data');
    }

    public function test_a_year_alone_means_that_whole_year(): void
    {
        // Legacy's resolveWindow(): a bare year is that year, never this month.
        Carbon::setTestNow('2026-06-15 10:00:00');
        $leave = $this->leave($this->people['staff'], '2025-03-10', '2025-03-10', 1);
        $this->actingAsScopedUser(self::VIEW, ScopeType::Company);

        $this->register('year=2025')
            ->assertOk()
            ->assertJsonPath('from', '2025-01-01')
            ->assertJsonPath('to', '2025-12-31')
            ->assertJsonPath('data.0.id', $leave->id);
    }

    public function test_a_window_date_must_be_written_in_full(): void
    {
        $this->actingAsScopedUser(self::VIEW, ScopeType::Company);

        $this->register('from=2026-6-1&to=2026-06-30')->assertStatus(422)->assertJsonValidationErrors('from');
    }

    public function test_no_window_means_the_current_month(): void
    {
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->actingAsScopedUser(self::VIEW, ScopeType::Company);

        $this->register('')->assertOk()->assertJsonPath('from', '2026-06-01')->assertJsonPath('to', '2026-06-30');
    }

    public function test_an_impossible_long_or_inverted_window_is_refused(): void
    {
        $this->actingAsScopedUser(self::VIEW, ScopeType::Company);

        $this->register('month=13&year=2026')->assertStatus(422)->assertJsonValidationErrors('month');
        $this->register('from=2026-01-01&to=2027-01-02')->assertStatus(422)->assertJsonValidationErrors('to');
        $this->register('from=2026-06-30&to=2026-06-01')->assertStatus(422)->assertJsonValidationErrors('to');
    }

    // ── Statuses ─────────────────────────────────────────────────────────

    public function test_approved_rows_and_excuses_are_the_default_and_pending_is_not(): void
    {
        $approved = $this->leave($this->people['staff'], '2026-06-01', '2026-06-01', 1);
        $excuse = $this->leave($this->people['staff'], '2026-06-05', '2026-06-05', 1, attributes: ['origin' => LeaveOrigin::HrExcuse]);
        $pending = $this->leave($this->people['staff'], '2026-06-10', '2026-06-10', 1, status: LeaveStatus::Pending);
        $this->actingAsScopedUser(self::VIEW, ScopeType::Company);

        $this->assertEqualsCanonicalizing([$approved->id, $excuse->id], $this->ids($this->register()));
        $this->assertEqualsCanonicalizing([$approved->id, $excuse->id, $pending->id], $this->ids($this->register('from=2026-06-01&to=2026-06-30&statuses=1,2')));
    }

    // ── The summary ──────────────────────────────────────────────────────

    public function test_the_summary_covers_the_whole_set_not_the_page(): void
    {
        $this->leave($this->people['staff'], '2026-05-30', '2026-06-02', 4);
        $this->leave($this->people['staff'], '2026-06-10', '2026-06-11', 2, 'sick');
        $this->leave($this->people['bStaff'], '2026-06-20', '2026-06-20', 1, attributes: ['origin' => LeaveOrigin::HrExcuse]);
        $this->actingAsScopedUser(self::VIEW, ScopeType::Company);

        $response = $this->register('from=2026-06-01&to=2026-06-30&per_page=1')->assertOk()->assertJsonCount(1, 'data');

        $this->assertSame([
            'employees'     => 2,
            'requests'      => 3,
            'days'          => 5,
            'calendar_days' => 5,
            'deducted_days' => 3,
            'excuses'       => 1,
        ], array_intersect_key($response->json('summary'), array_flip(['employees', 'requests', 'days', 'calendar_days', 'deducted_days', 'excuses'])));

        $byType = collect($response->json('summary.by_type'))->keyBy('name');
        $this->assertSame(['requests' => 2, 'employees' => 2, 'days' => 3], array_intersect_key($byType['إجازة سنوية'], array_flip(['requests', 'employees', 'days'])));
        $this->assertSame(2, $byType['إجازة مرضية']['days']);
        $this->assertSame('إجازة سنوية', $response->json('summary.by_type.0.name'), 'most days first');
        $this->assertSame([(string) LeaveStatus::Approved->value => 3], $response->json('summary.by_status'));
    }

    // ── Filters and sorts ────────────────────────────────────────────────

    public function test_it_filters_by_unit_with_its_subtree_by_search_and_by_type(): void
    {
        $a1 = $this->leave($this->people['staff'], '2026-06-10', '2026-06-10', 1);
        $b = $this->leave($this->people['bStaff'], '2026-06-11', '2026-06-11', 1, 'sick');
        $this->actingAsScopedUser(self::VIEW, ScopeType::Company);

        $this->assertSame([$a1->id], $this->ids($this->register('from=2026-06-01&to=2026-06-30&department_id='.$this->units['a']->id)));
        $this->assertSame([$b->id], $this->ids($this->register('from=2026-06-01&to=2026-06-30&search='.$this->people['bStaff']->employee_number)));
        $this->assertSame([$b->id], $this->ids($this->register('from=2026-06-01&to=2026-06-30&leave_type_id='.$this->type('sick')->id)));
    }

    public function test_it_sorts_by_start_date_by_default_and_by_other_keys(): void
    {
        $late = $this->leave($this->people['staff'], '2026-06-20', '2026-06-20', 1);
        $early = $this->leave($this->people['bStaff'], '2026-06-02', '2026-06-04', 3);
        $this->actingAsScopedUser(self::VIEW, ScopeType::Company);

        $this->assertSame([$early->id, $late->id], $this->ids($this->register()));
        $this->assertSame([$late->id, $early->id], $this->ids($this->register('from=2026-06-01&to=2026-06-30&sort=-start_date')));
        $this->assertSame([$late->id, $early->id], $this->ids($this->register('from=2026-06-01&to=2026-06-30&sort=requested_days')));
        $this->register('from=2026-06-01&to=2026-06-30&sort=salary')->assertStatus(422)->assertJsonValidationErrors('sort');
    }

    public function test_each_row_carries_the_request_shape(): void
    {
        $this->leave($this->people['staff'], '2026-06-10', '2026-06-11', 2);
        $this->actingAsScopedUser(self::VIEW, ScopeType::Company);

        $this->register()
            ->assertJsonPath('data.0.employee.id', $this->people['staff']->id)
            ->assertJsonPath('data.0.leave_type.code', 'annual')
            ->assertJsonPath('data.0.status_label', 'معتمدة')
            ->assertJsonPath('meta.total', 1);
    }
}
