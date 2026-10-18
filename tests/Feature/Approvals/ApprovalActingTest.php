<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Modules\Approvals\Enums\ApprovalStatus;
use App\Modules\Approvals\Enums\Decision;
use App\Modules\Approvals\Enums\RejectionPolicy;
use App\Modules\Approvals\Enums\SkipReason;
use App\Modules\Approvals\Enums\StepStatus;
use App\Modules\Approvals\Exceptions\ApprovalException;
use App\Modules\Approvals\Models\Approval;
use App\Modules\Approvals\Support\ApprovalRoute;
use App\Modules\Approvals\Support\StepDefinition;
use App\Modules\Auth\Models\Notification;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Shared\Support\NotificationEventCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Feature\Approvals\Concerns\ApprovalFixtures;
use Tests\Feature\Approvals\Fakes\FakeApprovable;
use Tests\TestCase;

/**
 * Acting on a step — approve, reject (design §5), against a fake subject.
 *
 * Legacy's leave rules (LeaveRequestSequentialApprovalTest): steps act in
 * order, only the last approval is final, any rejection ends the request and
 * closes the steps behind it, the balance is re-checked at the final approval
 * only. Legacy's document rules (DocumentWorkflowService): parallel managers,
 * a manager's rejection advisory, the chief last and decisive.
 */
class ApprovalActingTest extends TestCase
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

    private function assertNotTheirTurn(callable $act, string $message = 'ليس دورك في اعتماد هذا الطلب.'): void
    {
        try {
            $act();
            $this->fail('The action should have been refused.');
        } catch (ApprovalException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertSame(422, $e->statusCode());
        }
    }

    private function subjectOf(Approval $approval): FakeApprovable
    {
        return FakeApprovable::findOrFail($approval->approvable_id);
    }

    // ── Leave's rules: sequential, a rejection ends it ───────────────────

    public function test_steps_act_in_order_and_only_the_last_approval_is_final(): void
    {
        $approval = $this->submit($this->people['staff']);

        // The second approver is on the chain but cannot act early.
        $this->assertNotTheirTurn(fn () => $this->decide($approval, $this->people['hrHead']));

        $approval = $this->decide($approval, $this->people['headA']);
        $this->assertSame(ApprovalStatus::Pending, $approval->status);
        $this->assertNull($approval->decided_at);
        $this->assertSame(2, $approval->currentOrder());
        $this->assertNull($this->subjectOf($approval)->outcome, 'an intermediate approval tells the subject nothing');

        // The second approver is told it is their turn.
        $turn = Notification::where('user_id', $this->accountOf($this->people['hrHead'])->id)->sole();
        $this->assertSame(NotificationEventCatalogue::APPROVAL_STEP_PENDING, $turn->type);
        $this->assertSame('عنوان YourTurn', $turn->title);

        $approval = $this->decide($approval, $this->people['hrHead']);
        $this->assertSame(ApprovalStatus::Approved, $approval->status);
        $this->assertNotNull($approval->decided_at);
        $this->assertNull($approval->currentOrder());

        $subject = $this->subjectOf($approval);
        $this->assertSame('Approved', $subject->outcome);
        $this->assertSame(1, $subject->decided_count);
    }

    public function test_a_rejection_ends_the_request_and_closes_the_steps_behind_it(): void
    {
        [$first, $second, $third] = [$this->person(), $this->person(), $this->person()];
        $approval = $this->submit($this->people['staff'], $this->namedRoute(RejectionPolicy::Ends, $first, $second, $third));

        $this->decide($approval, $first);
        $approval = $this->decide($approval, $second, Decision::Reject);

        $this->assertSame(ApprovalStatus::Rejected, $approval->status);
        $this->assertNotNull($approval->decided_at);
        $this->assertSame(
            [StepStatus::Approved, StepStatus::Rejected, StepStatus::Skipped],
            $approval->steps->pluck('status')->all(),
        );
        // Not pending: nobody will ever be asked for this decision.
        $this->assertSame(SkipReason::ClosedByRejection, $approval->steps[2]->skip_reason);
        $this->assertSame('Rejected', $this->subjectOf($approval)->outcome);

        $this->assertNotTheirTurn(
            fn () => $this->decide($approval, $third),
            'يمكن اتخاذ القرار في الطلبات قيد الاعتماد فقط.',
        );
        $this->assertSame([], $this->notificationsOf($third), 'the closed step never became anyone\'s turn');
    }

    public function test_a_first_step_rejection_ends_it_at_once(): void
    {
        $approval = $this->decide($this->submit($this->people['staff']), $this->people['headA'], Decision::Reject);

        $this->assertSame(ApprovalStatus::Rejected, $approval->status);
        $this->assertSame([StepStatus::Rejected, StepStatus::Skipped], $approval->steps->pluck('status')->all());
        $this->assertSame([], $this->notificationsOf($this->people['hrHead']));
    }

    public function test_one_approval_completes_a_chain_collapsed_by_the_same_approver_rule(): void
    {
        $approval = $this->decide($this->submit($this->people['hrStaff']), $this->people['hrHead']);

        $this->assertSame(ApprovalStatus::Approved, $approval->status);
    }

    public function test_the_general_managers_approval_alone_completes_hr_heads_own_request(): void
    {
        $approval = $this->decide($this->submit($this->people['hrHead']), $this->people['gm']);

        $this->assertSame(ApprovalStatus::Approved, $approval->status);
        $this->assertSame('Approved', $this->subjectOf($approval)->outcome);
    }

    // ── Who may act ──────────────────────────────────────────────────────

    public function test_only_the_steps_own_approver_may_act(): void
    {
        $approval = $this->submit($this->people['staff']);
        $step = $this->stepOf($approval, $this->people['headA']);

        // Someone off the chain, an account with no employee, the requester.
        $bystander = $this->accountOf($this->person());
        $serviceAccount = User::factory()->create();
        $requester = $this->accountOf($this->people['staff']);

        foreach ([$bystander, $serviceAccount, $requester] as $user) {
            $this->assertNotTheirTurn(fn () => $this->engine()->act($step, $user, Decision::Approve));
        }

        $this->assertSame(StepStatus::Pending, $step->fresh()->status);
    }

    public function test_a_super_administrator_is_not_an_approver(): void
    {
        // Legacy's rule: being an administrator does not make it your turn.
        $this->seedRbac();
        $admin = $this->accountOf($this->person());
        $admin->roles()->attach(Role::where('name', User::SUPER_ADMIN_ROLE)->value('id'));
        $this->assertTrue($admin->fresh()->isSuperAdmin());

        $approval = $this->submit($this->people['staff']);

        $this->assertNotTheirTurn(fn () => $this->engine()->act($this->stepOf($approval, $this->people['headA']), $admin->fresh(), Decision::Approve));
    }

    public function test_a_decided_step_cannot_be_decided_again(): void
    {
        $approval = $this->submit($this->people['staff']);
        $this->decide($approval, $this->people['headA']);

        $this->assertNotTheirTurn(fn () => $this->decide($approval, $this->people['headA'], Decision::Reject));
        $this->assertSame(StepStatus::Approved, $this->stepOf($approval, $this->people['headA'])->status);
    }

    public function test_the_acting_account_and_time_are_recorded(): void
    {
        $approval = $this->decide($this->submit($this->people['staff']), $this->people['headA']);

        $step = $approval->steps[0];
        $this->assertSame($this->accountOf($this->people['headA'])->id, (int) $step->acted_by);
        $this->assertNotNull($step->acted_at);
    }

    // ── A note on a decision (§9 Q6 = A) ─────────────────────────────────

    public function test_a_note_is_optional_on_approve_and_on_reject(): void
    {
        $approval = $this->submit($this->people['staff']);

        $approval = $this->decide($approval, $this->people['headA'], Decision::Approve, '  موافق، مع التنسيق مع القسم  ');
        $this->assertSame('موافق، مع التنسيق مع القسم', $approval->steps[0]->note);

        $approval = $this->decide($approval, $this->people['hrHead'], Decision::Reject, 'الرصيد لا يسمح');
        $this->assertSame('الرصيد لا يسمح', $approval->steps[1]->note);
    }

    public function test_a_blank_note_is_stored_as_none(): void
    {
        $approval = $this->decide($this->submit($this->people['staff']), $this->people['headA'], Decision::Approve, '   ');

        $this->assertNull($approval->steps[0]->note);
    }

    // ── The final-approval guard ─────────────────────────────────────────

    public function test_the_subject_may_refuse_the_final_approval_and_nothing_changes(): void
    {
        $subject = $this->subjectFor($this->people['staff'], ['refusal' => 'رصيد الإجازات لا يكفي.']);
        $approval = $this->engine()->submit($subject, $this->leaveRoute());

        // Only the FINAL approval is guarded: step 1 records progress.
        $this->decide($approval, $this->people['headA']);

        $this->assertNotTheirTurn(fn () => $this->decide($approval, $this->people['hrHead']), 'رصيد الإجازات لا يكفي.');

        $approval->refresh()->load('steps');
        $this->assertSame(ApprovalStatus::Pending, $approval->status);
        $this->assertSame(StepStatus::Pending, $approval->steps[1]->status);
        $this->assertNull($approval->steps[1]->acted_at);
        $this->assertNull($subject->fresh()->outcome);

        // A rejection is never guarded.
        $approval = $this->decide($approval, $this->people['hrHead'], Decision::Reject);
        $this->assertSame(ApprovalStatus::Rejected, $approval->status);
    }

    public function test_a_failing_completion_hook_rolls_the_decision_back(): void
    {
        $approval = $this->submit($this->people['staff']);
        $this->decide($approval, $this->people['headA']);

        FakeApprovable::$failOnDecide = true;

        try {
            $this->decide($approval, $this->people['hrHead']);
            $this->fail('The hook should have thrown.');
        } catch (RuntimeException) {
        }

        // The approval and the subject cannot disagree: neither moved.
        $approval->refresh()->load('steps');
        $this->assertSame(ApprovalStatus::Pending, $approval->status);
        $this->assertSame(StepStatus::Pending, $approval->steps[1]->status);
    }

    // ── Documents' rules: parallel, advisory, a decisive chief ───────────

    public function test_parallel_managers_act_in_any_order_and_the_chief_only_after_all(): void
    {
        [$m1, $m2, $chief] = [$this->person(), $this->person(), $this->person()];
        $approval = $this->submit($this->people['staff'], ApprovalRoute::stages(
            RejectionPolicy::Advisory,
            [StepDefinition::namedEmployee($m1->id), StepDefinition::namedEmployee($m2->id)],
            [StepDefinition::namedEmployee($chief->id)->decisive()],
        ));

        $this->assertNotTheirTurn(fn () => $this->decide($approval, $chief));

        $approval = $this->decide($approval, $m2);
        $this->assertSame(1, $approval->currentOrder(), 'the order waits for every parallel step');
        $this->assertSame([], $this->notificationsOf($chief));

        $approval = $this->decide($approval, $m1);
        $this->assertSame(2, $approval->currentOrder());
        $this->assertSame([NotificationEventCatalogue::APPROVAL_STEP_PENDING], $this->notificationsOf($chief));

        $approval = $this->decide($approval, $chief);
        $this->assertSame(ApprovalStatus::Approved, $approval->status);
    }

    public function test_a_managers_rejection_is_advisory_and_the_approval_goes_on(): void
    {
        [$m1, $m2, $chief] = [$this->person(), $this->person(), $this->person()];
        $approval = $this->submit($this->people['staff'], ApprovalRoute::sequential(
            RejectionPolicy::Advisory,
            StepDefinition::namedEmployee($m1->id),
            StepDefinition::namedEmployee($m2->id),
            StepDefinition::namedEmployee($chief->id)->decisive(),
        ));

        $approval = $this->decide($approval, $m1, Decision::Reject, 'ملاحظات على الصياغة');

        $this->assertSame(ApprovalStatus::Pending, $approval->status);
        $this->assertSame(StepStatus::Rejected, $approval->steps[0]->status);
        $this->assertSame(2, $approval->currentOrder(), 'a rejection advances the chain as an approval would');
        $this->assertSame([NotificationEventCatalogue::APPROVAL_STEP_PENDING], $this->notificationsOf($m2));

        $this->decide($approval, $m2);
        $approval = $this->decide($approval, $chief);

        // The chief's decision is the outcome, the manager's rejection kept.
        $this->assertSame(ApprovalStatus::Approved, $approval->status);
        $this->assertSame([StepStatus::Rejected, StepStatus::Approved, StepStatus::Approved], $approval->steps->pluck('status')->all());
    }

    public function test_the_decisive_steps_rejection_ends_it(): void
    {
        [$m1, $chief] = [$this->person(), $this->person()];
        $approval = $this->submit($this->people['staff'], ApprovalRoute::sequential(
            RejectionPolicy::Advisory,
            StepDefinition::namedEmployee($m1->id),
            StepDefinition::namedEmployee($chief->id)->decisive(),
        ));

        $this->decide($approval, $m1);
        $approval = $this->decide($approval, $chief, Decision::Reject);

        $this->assertSame(ApprovalStatus::Rejected, $approval->status);
        $this->assertSame('Rejected', $this->subjectOf($approval)->outcome);
    }
}
