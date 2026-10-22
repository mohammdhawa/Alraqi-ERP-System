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
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Approvals\Concerns\ApprovalFixtures;
use Tests\Feature\Approvals\Fakes\FakeApprovable;
use Tests\TestCase;

/**
 * Withdrawing a pending request — NEW, agreed (§9 Q1 = B): the requester may
 * take it back while no step has been decided. Legacy has no withdrawal.
 */
class ApprovalWithdrawalTest extends TestCase
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

    private function withdraw(Approval $approval, ?User $by = null): Approval
    {
        return $this->engine()->withdraw($approval, $by ?? $this->accountOf($this->people['staff']));
    }

    private function assertRefused(callable $withdraw, string $message, int $status = 422): void
    {
        try {
            $withdraw();
            $this->fail('The withdrawal should have been refused.');
        } catch (ApprovalException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertSame($status, $e->statusCode());
        }
    }

    public function test_the_requester_withdraws_before_any_decision(): void
    {
        $approval = $this->withdraw($this->submit($this->people['staff']));

        $this->assertSame(ApprovalStatus::Cancelled, $approval->status);
        $this->assertNotNull($approval->decided_at);
        $this->assertSame([StepStatus::Skipped, StepStatus::Skipped], $approval->steps->pluck('status')->all());
        $this->assertSame(
            [SkipReason::ClosedByCancellation, SkipReason::ClosedByCancellation],
            $approval->steps->pluck('skip_reason')->all(),
        );

        $subject = FakeApprovable::findOrFail($approval->approvable_id);
        $this->assertSame('Cancelled', $subject->outcome);
        $this->assertSame(1, $subject->decided_count);

        // Gone from the approver's inbox, and nothing left to decide.
        $this->assertSame(0, app(ApprovalInbox::class)->pendingFor($this->accountOf($this->people['headA']))->count());
        $this->assertRefused(
            fn () => $this->decide($approval, $this->people['headA']),
            'يمكن اتخاذ القرار في الطلبات قيد الاعتماد فقط.',
        );
    }

    public function test_once_a_step_is_decided_the_request_cannot_be_withdrawn(): void
    {
        $approval = $this->submit($this->people['staff']);
        $this->decide($approval, $this->people['headA']);

        $this->assertRefused(fn () => $this->withdraw($approval), 'لا يمكن سحب الطلب بعد أن اتُّخذ فيه قرار.');
        $this->assertSame(ApprovalStatus::Pending, $approval->fresh()->status);
    }

    public function test_a_step_skipped_at_submission_is_not_a_decision(): void
    {
        // hrStaff's chain collapses to one step; the skip does not block it.
        $approval = $this->submit($this->people['hrStaff']);
        $this->assertSame(StepStatus::Skipped, $approval->steps[1]->status);

        $approval = $this->withdraw($approval, $this->accountOf($this->people['hrStaff']));

        $this->assertSame(ApprovalStatus::Cancelled, $approval->status);
        // The submission-time skip keeps its own reason.
        $this->assertSame([SkipReason::ClosedByCancellation, SkipReason::SameApprover], $approval->steps->pluck('skip_reason')->all());
    }

    public function test_only_the_requester_may_withdraw(): void
    {
        $approval = $this->submit($this->people['staff']);

        foreach ([$this->accountOf($this->people['headA']), User::factory()->create()] as $other) {
            $this->assertRefused(fn () => $this->withdraw($approval, $other), 'لا يمكن سحب طلب لم تقدّمه.', 403);
        }

        $this->assertSame(ApprovalStatus::Pending, $approval->fresh()->status);
    }

    public function test_a_decided_request_cannot_be_withdrawn(): void
    {
        $approval = $this->decide($this->submit($this->people['staff']), $this->people['headA'], Decision::Reject);

        $this->assertRefused(fn () => $this->withdraw($approval), 'لا يمكن سحب طلب ليس قيد الاعتماد.');
        $this->assertSame(ApprovalStatus::Rejected, $approval->fresh()->status);
    }
}
