<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Modules\Leave\Enums\LeaveOrigin;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Leave\Concerns\LeaveFixtures;
use Tests\TestCase;

/**
 * The employee's own leave — legacy's own-requests list
 * (LeaveRequestQueryControlsTest, its first half) and the withdrawal the
 * engine allows before any decision (§9 Q1 = B), as `/cancel`.
 */
class MyLeaveRequestsTest extends TestCase
{
    use LeaveFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLeaveTree();
    }

    private function row(string $type, LeaveStatus $status, string $start, string $end, array $attributes = []): LeaveRequest
    {
        return LeaveRequest::factory()->forType($type)->create([
            'employee_id' => $this->people['staff']->id, 'status' => $status, 'start_date' => $start, 'end_date' => $end,
        ] + $attributes);
    }

    private function ids(TestResponse $response): array
    {
        return array_column($response->assertOk()->json('data'), 'id');
    }

    private function fileLeave(string $start = '2026-06-21', string $end = '2026-06-22'): TestResponse
    {
        return $this->postJson('/api/me/leave-requests', [
            'leave_type_id' => $this->type('annual')->id, 'start_date' => $start, 'end_date' => $end,
        ]);
    }

    // ── The list ─────────────────────────────────────────────────────────

    public function test_the_list_holds_the_callers_own_newest_first(): void
    {
        $older = $this->row('annual', LeaveStatus::Approved, '2026-03-01', '2026-03-02');
        $newer = $this->row('sick', LeaveStatus::Pending, '2026-05-01', '2026-05-02');
        LeaveRequest::factory()->create(['employee_id' => $this->people['bStaff']->id]);
        $this->actingAsPerson($this->people['staff']);

        $this->assertSame([$newer->id, $older->id], $this->ids($this->getJson('/api/me/leave-requests')));
        $this->getJson('/api/me/leave-requests')->assertJsonPath('data.0.leave_type.code', 'sick')->assertJsonPath('meta.total', 2);
    }

    public function test_it_filters_by_status_type_year_month_and_window(): void
    {
        $march = $this->row('annual', LeaveStatus::Approved, '2026-03-10', '2026-03-12');
        $may = $this->row('sick', LeaveStatus::Pending, '2026-05-01', '2026-05-02');
        $rejected = $this->row('annual', LeaveStatus::Rejected, '2025-12-30', '2026-01-02');
        $this->actingAsPerson($this->people['staff']);

        $this->assertSame([$march->id], $this->ids($this->getJson('/api/me/leave-requests?status=2')));
        $this->assertEqualsCanonicalizing([$march->id, $may->id], $this->ids($this->getJson('/api/me/leave-requests?statuses=1,2')));
        $this->assertSame([$may->id], $this->ids($this->getJson('/api/me/leave-requests?leave_type_id='.$this->type('sick')->id)));
        $this->assertSame([$rejected->id], $this->ids($this->getJson('/api/me/leave-requests?year=2025')));
        $this->assertSame([$march->id], $this->ids($this->getJson('/api/me/leave-requests?month=3&year=2026')));
        $this->assertSame([$rejected->id], $this->ids($this->getJson('/api/me/leave-requests?date_from=2026-01-01&date_to=2026-01-01')), 'a span overlapping the window');
    }

    public function test_hrs_excuses_for_the_caller_are_listed_and_can_be_separated(): void
    {
        $excuse = $this->row('sick', LeaveStatus::Approved, '2026-02-01', '2026-02-01', ['origin' => LeaveOrigin::HrExcuse]);
        $own = $this->row('annual', LeaveStatus::Pending, '2026-04-01', '2026-04-01');
        $this->actingAsPerson($this->people['staff']);

        $this->assertSame([$excuse->id], $this->ids($this->getJson('/api/me/leave-requests?is_excuse=1')));
        $this->assertSame([$own->id], $this->ids($this->getJson('/api/me/leave-requests?is_excuse=0')));
    }

    public function test_it_sorts_and_refuses_what_it_does_not_know(): void
    {
        $long = $this->row('annual', LeaveStatus::Approved, '2026-03-01', '2026-03-05', ['requested_days' => 5]);
        $short = $this->row('annual', LeaveStatus::Approved, '2026-01-01', '2026-01-01', ['requested_days' => 1]);
        $this->actingAsPerson($this->people['staff']);

        $this->assertSame([$short->id, $long->id], $this->ids($this->getJson('/api/me/leave-requests?sort=start_date')));
        $this->assertSame([$long->id, $short->id], $this->ids($this->getJson('/api/me/leave-requests?sort=-requested_days')));
        $this->getJson('/api/me/leave-requests?sort=salary')->assertStatus(422)->assertJsonValidationErrors('sort');
        $this->getJson('/api/me/leave-requests?month=13')->assertStatus(422)->assertJsonValidationErrors('month');
        $this->getJson('/api/me/leave-requests?status=9')->assertStatus(422)->assertJsonValidationErrors('status');
    }

    // ── Withdrawing ──────────────────────────────────────────────────────

    public function test_a_pending_request_is_withdrawn_and_its_days_free_again(): void
    {
        $this->actingAsPerson($this->people['staff']);
        $id = $this->fileLeave()->assertCreated()->json('data.leave_request.id');

        $this->postJson("/api/me/leave-requests/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('message', 'تم سحب طلب الإجازة.')
            ->assertJsonPath('data.status', LeaveStatus::Cancelled->value)
            ->assertJsonPath('data.approval.status_label', 'مسحوب');

        $this->fileLeave()->assertCreated();
    }

    /**
     * The approver is not notified of a withdrawal (owner, 2026-10-20,
     * parity audit C-5); opening the request from their earlier notification
     * shows that it was withdrawn, by whom and when.
     */
    public function test_the_approver_opening_a_withdrawn_request_sees_who_withdrew_it_and_when(): void
    {
        $requester = $this->actingAsPerson($this->people['staff']);
        $id = $this->fileLeave()->assertCreated()->json('data.leave_request.id');
        $this->postJson("/api/me/leave-requests/{$id}/cancel")->assertOk();

        $this->actingAsPerson($this->people['headA']);

        $this->getJson("/api/leave/requests/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', LeaveStatus::Cancelled->value)
            ->assertJsonPath('data.cancelled_by.id', $requester->id)
            ->assertJsonPath('data.approval.status_label', 'مسحوب')
            ->assertJsonPath('data.approval.steps.0.skip_reason_label', 'أُغلقت بسحب الطلب');

        $this->assertNotNull(LeaveRequest::findOrFail($id)->cancelled_at);
    }

    public function test_not_once_an_approver_has_decided(): void
    {
        $this->actingAsPerson($this->people['staff']);
        $leave = $this->fileLeave()->assertCreated()->json('data.leave_request');

        $this->actingAsPerson($this->people['headA']);
        $this->postJson("/api/approvals/{$leave['approval']['id']}/steps/{$leave['approval']['steps'][0]['id']}/approve")->assertOk();

        $this->actingAsPerson($this->people['staff']);
        $this->postJson("/api/me/leave-requests/{$leave['id']}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن سحب الطلب بعد أن اتُّخذ فيه قرار.');
    }

    public function test_a_rejected_requests_days_can_be_filed_again(): void
    {
        $this->actingAsPerson($this->people['staff']);
        $leave = $this->fileLeave()->assertCreated()->json('data.leave_request');

        $this->actingAsPerson($this->people['headA']);
        $this->postJson("/api/approvals/{$leave['approval']['id']}/steps/{$leave['approval']['steps'][0]['id']}/reject")->assertOk();

        $this->actingAsPerson($this->people['staff']);
        $this->fileLeave()->assertCreated();
    }

    public function test_only_the_callers_own_request_can_be_withdrawn(): void
    {
        $this->actingAsPerson($this->people['staff']);
        $id = $this->fileLeave()->assertCreated()->json('data.leave_request.id');

        $this->actingAsPerson($this->people['bStaff']);
        $this->postJson("/api/me/leave-requests/{$id}/cancel")->assertNotFound();

        $this->assertSame(LeaveStatus::Pending, LeaveRequest::find($id)->status);
    }

    public function test_an_hr_excuse_cannot_be_withdrawn_here(): void
    {
        $excuse = $this->row('sick', LeaveStatus::Approved, '2026-02-01', '2026-02-01', ['origin' => LeaveOrigin::HrExcuse]);
        $this->actingAsPerson($this->people['staff']);

        $this->postJson("/api/me/leave-requests/{$excuse->id}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن سحب عذر سجّلته الموارد البشرية.');
    }
}
