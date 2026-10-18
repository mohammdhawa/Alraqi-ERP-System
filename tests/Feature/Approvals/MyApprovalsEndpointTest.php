<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Modules\Approvals\Enums\Decision;
use App\Modules\Approvals\Enums\StepStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Approvals\Concerns\ApprovalFixtures;
use Tests\TestCase;

/**
 * GET /api/me/approvals — the approver's inbox, the app's and the dashboard's
 * (design §7, 2026-10-14).
 *
 * Self-scoped: the steps are the caller's because the caller's employee is
 * their approver, never because of an id in the request. Each row carries the
 * subject's own summary (2026-10-18), so the list renders without a call per
 * row — legacy's queue listed whole leave requests.
 */
class MyApprovalsEndpointTest extends TestCase
{
    use ApprovalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApprovals();
    }

    protected function tearDown(): void
    {
        $this->tearDownApprovals();
        parent::tearDown();
    }

    private function inbox(string $query = ''): TestResponse
    {
        return $this->getJson('/api/me/approvals'.$query);
    }

    public function test_it_lists_the_steps_waiting_on_the_caller_with_the_subjects_summary(): void
    {
        $approval = $this->submit($this->people['staff']);
        $step = $this->stepOf($approval, $this->people['headA']);
        $this->actingAsPerson($this->people['headA']);

        $this->inbox()
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.step_id', $step->id)
            ->assertJsonPath('data.0.approval_id', $approval->id)
            ->assertJsonPath('data.0.step_order', 1)
            ->assertJsonPath('data.0.status', StepStatus::Pending->value)
            ->assertJsonPath('data.0.status_label', 'بانتظار القرار')
            ->assertJsonPath('data.0.subject.type', 'fake_approvable')
            ->assertJsonPath('data.0.subject.id', $approval->approvable_id)
            ->assertJsonPath('data.0.subject.title', 'طلب تجريبي')
            ->assertJsonPath('data.0.subject.summary', "الطلب رقم {$approval->approvable_id} من {$this->people['staff']->employee_number}")
            ->assertJsonPath('data.0.requester.id', $this->people['staff']->id)
            ->assertJsonPath('data.0.requester.employee_number', $this->people['staff']->employee_number)
            ->assertJsonPath('data.0.submitted_at', $approval->created_at->toIso8601String())
            ->assertJsonPath('data.0.acted_at', null)
            ->assertJsonPath('data.0.note', null);
    }

    public function test_a_later_step_reaches_the_inbox_only_when_its_turn_comes(): void
    {
        $approval = $this->submit($this->people['staff']);

        $this->actingAsPerson($this->people['hrHead']);
        $this->inbox()->assertOk()->assertJsonCount(0, 'data');

        $this->decide($approval, $this->people['headA']);

        $this->actingAsPerson($this->people['hrHead']);
        $this->inbox()->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.step_order', 2);
    }

    public function test_decided_lists_what_the_caller_decided_with_the_note(): void
    {
        $approval = $this->submit($this->people['staff']);
        $this->decide($approval, $this->people['headA'], Decision::Reject, 'لا يوجد بديل');
        $this->actingAsPerson($this->people['headA']);

        $this->inbox()->assertOk()->assertJsonCount(0, 'data');

        $this->inbox('?status=decided')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.approval_id', $approval->id)
            ->assertJsonPath('data.0.status', StepStatus::Rejected->value)
            ->assertJsonPath('data.0.note', 'لا يوجد بديل')
            ->assertJsonPath('data.0.acted_at', fn (?string $at): bool => $at !== null);
    }

    public function test_an_unknown_status_is_refused(): void
    {
        $this->actingAsPerson($this->people['headA']);

        $this->inbox('?status=everything')->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_an_account_with_no_employee_has_an_empty_inbox(): void
    {
        $this->submit($this->people['staff']);
        $this->actingAsRolelessUser();

        $this->inbox()->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_more_rows_cost_no_more_queries(): void
    {
        $this->submit($this->people['staff']);
        $one = $this->queriesFor(1);

        foreach (range(1, 3) as $ignored) {
            $this->submit($this->person($this->units['a1']));
        }

        $this->assertSame($one, $this->queriesFor(4), 'subjects, requesters and their summaries are eager-loaded');
    }

    public function test_a_guest_is_refused(): void
    {
        $this->inbox()->assertUnauthorized();
    }

    private function queriesFor(int $rows): int
    {
        $this->actingAsPerson($this->people['headA']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->inbox()->assertOk()->assertJsonCount($rows, 'data');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
