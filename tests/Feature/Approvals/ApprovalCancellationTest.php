<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Modules\Approvals\Enums\ApprovalStatus;
use App\Modules\Approvals\Enums\Decision;
use App\Modules\Approvals\Enums\SkipReason;
use App\Modules\Approvals\Enums\StepStatus;
use App\Modules\Approvals\Exceptions\ApprovalException;
use App\Modules\Approvals\Models\Approval;
use App\Modules\Approvals\Services\ApprovalInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Feature\Approvals\Concerns\ApprovalFixtures;
use Tests\Feature\Approvals\Fakes\FakeApprovable;
use Tests\TestCase;

/**
 * The administrator's cancellation (documents design §7.8): unlike the
 * requester's withdrawal it is allowed after decisions. The decided steps
 * stay as they were; the pending ones close. Only documents call it.
 */
class ApprovalCancellationTest extends TestCase
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

    private function assertRefused(Approval $approval): void
    {
        try {
            $this->engine()->cancel($approval);
            $this->fail('The cancellation should have been refused.');
        } catch (ApprovalException $e) {
            $this->assertSame('لا يمكن إغلاق طلب ليس قيد الاعتماد.', $e->getMessage());
            $this->assertSame(422, $e->statusCode());
        }
    }

    public function test_the_reason_is_numbered_and_labelled(): void
    {
        $this->assertSame(6, SkipReason::ClosedByAdministrator->value);
        $this->assertSame('أُغلقت بقرار مدير النظام', SkipReason::ClosedByAdministrator->label());
    }

    public function test_cancelling_after_a_decision_keeps_it_and_closes_the_rest(): void
    {
        $approval = $this->submit($this->people['staff']);
        $this->decide($approval, $this->people['headA'], Decision::Approve, 'موافق');
        $decided = $this->stepOf($approval, $this->people['headA']);

        $approval = $this->engine()->cancel($approval);

        $this->assertSame(ApprovalStatus::Cancelled, $approval->status);
        $this->assertNotNull($approval->decided_at);

        [$first, $second] = [$approval->steps[0], $approval->steps[1]];

        // The decision is left exactly as it was recorded.
        $this->assertSame(StepStatus::Approved, $first->status);
        $this->assertNull($first->skip_reason);
        $this->assertSame('موافق', $first->note);
        $this->assertSame($decided->acted_by, $first->acted_by);
        $this->assertEquals($decided->acted_at, $first->acted_at);

        $this->assertSame(StepStatus::Skipped, $second->status);
        $this->assertSame(SkipReason::ClosedByAdministrator, $second->skip_reason);
        $this->assertNull($second->acted_at);

        // Nothing is left to decide, and nobody's inbox holds it.
        $this->assertSame(0, app(ApprovalInbox::class)->pendingFor($this->accountOf($this->people['hrHead']))->count());
    }

    public function test_the_subject_is_told_once(): void
    {
        $approval = $this->submit($this->people['staff']);
        $this->decide($approval, $this->people['headA']);

        $this->engine()->cancel($approval);

        $subject = FakeApprovable::findOrFail($approval->approvable_id);
        $this->assertSame('Cancelled', $subject->outcome);
        $this->assertSame(1, $subject->decided_count);
    }

    public function test_it_is_allowed_before_any_decision_and_keeps_submission_skips(): void
    {
        // hrStaff's chain collapses to one step; the skip keeps its own reason.
        $approval = $this->engine()->cancel($this->submit($this->people['hrStaff']));

        $this->assertSame(ApprovalStatus::Cancelled, $approval->status);
        $this->assertSame(
            [SkipReason::ClosedByAdministrator, SkipReason::SameApprover],
            $approval->steps->pluck('skip_reason')->all(),
        );
    }

    public function test_a_request_that_is_not_pending_is_refused(): void
    {
        $rejected = $this->decide($this->submit($this->people['staff']), $this->people['headA'], Decision::Reject);
        $this->assertRefused($rejected);
        $this->assertSame(ApprovalStatus::Rejected, $rejected->fresh()->status);

        $cancelled = $this->engine()->cancel($this->submit($this->people['staff']));
        $this->assertRefused($cancelled);

        $subject = FakeApprovable::findOrFail($cancelled->approvable_id);
        $this->assertSame(1, $subject->decided_count);
    }

    public function test_a_subject_that_fails_rolls_the_cancellation_back(): void
    {
        $approval = $this->submit($this->people['staff']);
        FakeApprovable::$failOnDecide = true;

        try {
            $this->engine()->cancel($approval);
            $this->fail('The subject failure should have surfaced.');
        } catch (RuntimeException $e) {
            $this->assertSame('the subject failed to record the outcome', $e->getMessage());
        }

        $approval = $approval->fresh('steps');
        $this->assertSame(ApprovalStatus::Pending, $approval->status);
        $this->assertNull($approval->decided_at);
        $this->assertSame([StepStatus::Pending, StepStatus::Pending], $approval->steps->pluck('status')->all());
    }
}
