<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Modules\Approvals\Enums\ApprovalStatus;
use App\Modules\Auth\Enums\ScopeType;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Leave\Concerns\LeaveFixtures;
use Tests\TestCase;

/**
 * The approval lists, filtered as legacy's leave queue was (owner,
 * 2026-10-20, parity audit C-4): the approver's inbox and HR's list of every
 * approval. Real leave on the real chain, so the leave's own filters — its
 * type and its dates — are exercised through FiltersApprovals.
 *
 * headA approves for two people: «سامي» in A1 (annual, 21–22 June) and «رنا»
 * in A itself (emergency, 1–2 July). bStaff's leave goes to the general
 * manager (B has no manager of its own).
 */
class ApprovalListFiltersTest extends TestCase
{
    use LeaveFixtures;
    use RefreshDatabase;

    private Employee $sami;

    private Employee $rana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLeaveTree();

        $this->sami = Employee::factory()->named('سامي العلي')->create(['department_id' => $this->units['a1']->id]);
        $this->rana = Employee::factory()->named('رنا الحسن')->create(['department_id' => $this->units['a']->id]);
        foreach ([$this->sami, $this->rana] as $employee) {
            User::factory()->create(['employee_id' => $employee->id]);
        }

        $this->file($this->sami, 'annual', '2026-06-21', '2026-06-22');
        $this->file($this->rana, 'emergency', '2026-07-01', '2026-07-02');
        $this->file($this->people['bStaff'], 'annual', '2026-08-01', '2026-08-01');
    }

    private function file(Employee $employee, string $type, string $start, string $end): void
    {
        $this->actingAsPerson($employee);
        $this->postJson('/api/me/leave-requests', [
            'leave_type_id' => $this->type($type)->id, 'start_date' => $start, 'end_date' => $end,
        ])->assertCreated();
    }

    /** @return array<int, int> the requesters' ids, in the order listed */
    private function inbox(array $query = []): array
    {
        $this->actingAsPerson($this->people['headA']);

        return collect($this->getJson('/api/me/approvals?'.http_build_query($query))->assertOk()->json('data'))
            ->pluck('requester.id')->all();
    }

    // ── The approver's inbox ─────────────────────────────────────────────

    public function test_without_filters_the_inbox_is_unchanged_newest_first(): void
    {
        $this->assertSame([$this->rana->id, $this->sami->id], $this->inbox());
    }

    public function test_the_inbox_filters_on_the_leave_type(): void
    {
        $this->assertSame([$this->sami->id], $this->inbox(['leave_type_id' => $this->type('annual')->id]));
        $this->assertSame([$this->rana->id], $this->inbox(['leave_type_ids' => [$this->type('emergency')->id]]));
    }

    public function test_the_inbox_filters_on_dates_the_leave_overlaps(): void
    {
        $this->assertSame([$this->rana->id], $this->inbox(['date_from' => '2026-07-02', 'date_to' => '2026-07-31']));
        $this->assertSame([$this->sami->id], $this->inbox(['date_to' => '2026-06-21']));
    }

    public function test_the_inbox_filters_on_the_requesters_name_and_unit(): void
    {
        $this->assertSame([$this->rana->id], $this->inbox(['search' => 'رنا']));
        $this->assertSame([$this->sami->id], $this->inbox(['search' => $this->sami->employee_number]));

        // A unit is taken with its subtree: A covers A1 too.
        $this->assertSame([$this->sami->id], $this->inbox(['department_id' => $this->units['a1']->id]));
        $this->assertSame([$this->rana->id, $this->sami->id], $this->inbox(['department_ids' => [$this->units['a']->id]]));
    }

    public function test_the_inbox_filters_on_the_subject_type_and_the_filing_day(): void
    {
        $today = now()->toDateString();

        $this->assertCount(2, $this->inbox(['subject_type' => 'leave_request', 'submitted_from' => $today, 'submitted_to' => $today]));
        $this->assertSame([], $this->inbox(['submitted_to' => now()->subDay()->toDateString()]));
    }

    public function test_the_inbox_sorts_by_name_and_by_filing(): void
    {
        $this->assertSame([$this->rana->id, $this->sami->id], $this->inbox(['sort' => 'employee_name']));
        $this->assertSame([$this->sami->id, $this->rana->id], $this->inbox(['sort' => '-employee_name']));
        $this->assertSame([$this->sami->id, $this->rana->id], $this->inbox(['sort' => 'submitted_at']));
    }

    public function test_the_decided_half_takes_the_same_filters(): void
    {
        $this->actingAsPerson($this->people['headA']);
        foreach ($this->getJson('/api/me/approvals')->json('data') as $row) {
            $this->postJson("/api/approvals/{$row['approval_id']}/steps/{$row['step_id']}/approve")->assertOk();
        }

        $this->assertSame([$this->sami->id], $this->inbox(['status' => 'decided', 'leave_type_id' => $this->type('annual')->id]));
    }

    public function test_bad_filters_are_refused(): void
    {
        $this->actingAsPerson($this->people['headA']);

        $this->getJson('/api/me/approvals?sort=start_date')->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson('/api/me/approvals?leave_type_id=999999')->assertUnprocessable()->assertJsonValidationErrors('leave_type_id');
        $this->getJson('/api/me/approvals?date_from=2026-07-02&date_to=2026-07-01')->assertUnprocessable()->assertJsonValidationErrors('date_to');
        $this->getJson('/api/me/approvals?subject_type=nope')->assertUnprocessable()->assertJsonValidationErrors('subject_type');
    }

    // ── HR's list of every approval ──────────────────────────────────────

    public function test_hr_lists_every_approval_in_reach_with_whose_turn_it_is(): void
    {
        $this->actingAsScopedUser(['approvals.view'], ScopeType::Company);

        $rows = $this->getJson('/api/approvals')->assertOk()->assertJsonPath('meta.total', 3)->json('data');

        $this->assertSame([$this->people['bStaff']->id, $this->rana->id, $this->sami->id], array_column(array_column($rows, 'requester'), 'id'));
        $this->assertSame($this->people['gm']->id, $rows[0]['current_steps'][0]['approver']['id']);
        $this->assertSame($this->people['headA']->id, $rows[1]['current_steps'][0]['approver']['id']);
        $this->assertSame('إدارة أ', $rows[1]['department']['name']);
        $this->assertSame('leave_request', $rows[1]['subject']['type']);
        $this->assertFalse($rows[1]['can_reassign']);
    }

    public function test_hr_filters_by_status_and_sees_decided_ones_too(): void
    {
        $this->actingAsPerson($this->people['gm']);
        $row = collect($this->getJson('/api/me/approvals')->json('data'))->sole();
        $this->postJson("/api/approvals/{$row['approval_id']}/steps/{$row['step_id']}/reject")->assertOk();

        $this->actingAsScopedUser(['approvals.view', 'approvals.reassign'], ScopeType::Company);

        $this->getJson('/api/approvals?status='.ApprovalStatus::Rejected->value)
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.requester.id', $this->people['bStaff']->id)
            ->assertJsonPath('data.0.current_steps', [])
            ->assertJsonPath('data.0.can_reassign', false);

        $this->getJson('/api/approvals?statuses[]='.ApprovalStatus::Pending->value.'&leave_type_id='.$this->type('emergency')->id)
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.requester.id', $this->rana->id)
            ->assertJsonPath('data.0.can_reassign', true);
    }

    public function test_a_unit_scoped_holder_sees_only_their_units_requests(): void
    {
        $this->actingAsScopedUser(['approvals.view'], ScopeType::Unit, $this->units['b']->id);

        $this->getJson('/api/approvals')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.requester.id', $this->people['bStaff']->id);
    }

    public function test_the_list_needs_approvals_view(): void
    {
        $this->actingAsPerson($this->people['headA']);

        $this->getJson('/api/approvals')->assertForbidden();
    }

    public function test_hr_sorts_by_name(): void
    {
        // Pinned: the factory's random name would make the order a coin toss.
        $this->people['bStaff']->update(['first_name' => 'يوسف']);
        $this->actingAsScopedUser(['approvals.view'], ScopeType::Company);

        $ids = array_column(array_column($this->getJson('/api/approvals?sort=employee_name')->json('data'), 'requester'), 'id');

        $this->assertSame([$this->rana->id, $this->sami->id, $this->people['bStaff']->id], $ids);
    }
}
