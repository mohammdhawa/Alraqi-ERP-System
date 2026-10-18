<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Modules\Approvals\Enums\ApprovalStatus;
use App\Modules\Approvals\Enums\Decision;
use App\Modules\Approvals\Enums\RejectionPolicy;
use App\Modules\Approvals\Enums\SkipReason;
use App\Modules\Approvals\Enums\StepResolver;
use App\Modules\Approvals\Enums\StepStatus;
use App\Modules\Approvals\Exceptions\ApprovalException;
use App\Modules\Approvals\Models\Approval;
use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Approvals\Support\ApprovalRoute;
use App\Modules\Approvals\Support\StepDefinition;
use App\Modules\Auth\Models\Notification;
use App\Shared\Models\AuditLog;
use App\Shared\Support\NotificationEventCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Feature\Approvals\Concerns\ApprovalFixtures;
use Tests\Feature\Approvals\Fakes\FakeApprovable;
use Tests\TestCase;

/**
 * Building the chain — submission (design §4), against a fake subject.
 *
 * Approvers are RESOLVED, never chosen (decision round Q1 = B); a step that
 * repeats the step before it, or names the requester, is skipped with its
 * reason (Q2 = A / A); a step that resolves to nobody, or to someone with no
 * active account, refuses the submission (§9 Q2, Q8); and the chain is frozen
 * once saved (§9 Q3). Legacy's ordered workflow, notifying only the first
 * approver, is the first test.
 */
class ApprovalSubmissionTest extends TestCase
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

    private function assertRefused(callable $submit, string $message): void
    {
        try {
            $submit();
            $this->fail('The submission should have been refused.');
        } catch (ApprovalException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertSame(422, $e->statusCode());
        }

        // Nothing is saved: HR fixes the chart and the employee submits again.
        $this->assertSame(0, Approval::withTrashed()->count());
        $this->assertSame(0, ApprovalStep::withTrashed()->count());
    }

    // ── The resolved chain ───────────────────────────────────────────────

    public function test_submission_resolves_an_ordered_chain_and_notifies_only_the_first_approver(): void
    {
        $approval = $this->submit($this->people['staff']);

        $this->assertSame(ApprovalStatus::Pending, $approval->status);
        $this->assertSame(RejectionPolicy::Ends, $approval->rejection_policy);
        $this->assertSame($this->people['staff']->id, (int) $approval->requested_by);
        $this->assertSame(FakeApprovable::MORPH_ALIAS, $approval->approvable_type);

        $steps = $approval->steps;
        $this->assertCount(2, $steps);

        // Step 1: the direct manager, climbing past the vacant A1 to headA.
        $this->assertSame(1, $steps[0]->step_order);
        $this->assertSame(StepResolver::DirectManager, $steps[0]->resolver);
        $this->assertSame($this->people['headA']->id, (int) $steps[0]->approver_id);
        $this->assertSame(StepStatus::Pending, $steps[0]->status);

        // Step 2: the manager of the unit coded HR.
        $this->assertSame(2, $steps[1]->step_order);
        $this->assertSame(StepResolver::UnitManager, $steps[1]->resolver);
        $this->assertSame('HR', $steps[1]->resolver_ref);
        $this->assertSame($this->people['hrHead']->id, (int) $steps[1]->approver_id);
        $this->assertSame(StepStatus::Pending, $steps[1]->status);

        $this->assertSame(1, $approval->currentOrder());

        $notification = Notification::where('user_id', $this->accountOf($this->people['headA'])->id)->sole();
        $this->assertSame(NotificationEventCatalogue::APPROVAL_STEP_PENDING, $notification->type);
        $this->assertSame('عنوان Submitted', $notification->title);
        $this->assertSame(FakeApprovable::MORPH_ALIAS, $notification->reference_type);
        $this->assertSame((int) $approval->approvable_id, (int) $notification->reference_id);

        $this->assertSame([], $this->notificationsOf($this->people['hrHead']));
    }

    public function test_a_step_repeating_the_step_before_it_is_skipped(): void
    {
        // An HR staff member's direct manager IS مدير الموارد: one approval.
        $approval = $this->submit($this->people['hrStaff']);

        [$first, $second] = $approval->steps->all();
        $this->assertSame($this->people['hrHead']->id, (int) $first->approver_id);
        $this->assertSame(StepStatus::Pending, $first->status);

        $this->assertSame($this->people['hrHead']->id, (int) $second->approver_id);
        $this->assertSame(StepStatus::Skipped, $second->status);
        $this->assertSame(SkipReason::SameApprover, $second->skip_reason);

        // Notified once, for the one step they hold.
        $this->assertSame([NotificationEventCatalogue::APPROVAL_STEP_PENDING], $this->notificationsOf($this->people['hrHead']));
    }

    public function test_a_step_naming_the_requester_is_skipped(): void
    {
        // مدير الموارد's own leave: the chain climbs past them to the general
        // manager, and step 2 — themselves — is skipped.
        $approval = $this->submit($this->people['hrHead']);

        [$first, $second] = $approval->steps->all();
        $this->assertSame($this->people['gm']->id, (int) $first->approver_id);
        $this->assertSame(StepStatus::Pending, $first->status);

        $this->assertSame($this->people['hrHead']->id, (int) $second->approver_id);
        $this->assertSame(StepStatus::Skipped, $second->status);
        $this->assertSame(SkipReason::Requester, $second->skip_reason);
    }

    public function test_the_same_approver_rule_compares_with_the_nearest_stage_still_asked(): void
    {
        $x = $this->person();
        $requester = $this->people['staff'];

        // [x], [requester → skipped], [x → same as the step before the skip].
        $approval = $this->submit($requester, $this->namedRoute(RejectionPolicy::Ends, $x, $requester, $x));

        $this->assertSame(
            [StepStatus::Pending, StepStatus::Skipped, StepStatus::Skipped],
            $approval->steps->pluck('status')->all(),
        );
        $this->assertSame(
            [null, SkipReason::Requester, SkipReason::SameApprover],
            $approval->steps->pluck('skip_reason')->all(),
        );
    }

    public function test_a_chain_with_nobody_left_to_ask_is_refused(): void
    {
        $requester = $this->people['staff'];

        $this->assertRefused(
            fn () => $this->submit($requester, $this->namedRoute(RejectionPolicy::Ends, $requester)),
            'لا يمكن تقديم الطلب: لا يوجد من يعتمده.',
        );
    }

    public function test_a_subject_is_submitted_once(): void
    {
        $subject = $this->subjectFor($this->people['staff']);
        $this->engine()->submit($subject, $this->leaveRoute());

        $this->expectExceptionObject(new ApprovalException('هذا الطلب مُقدَّم للاعتماد مسبقاً.'));
        $this->engine()->submit($subject, $this->leaveRoute());
    }

    // ── A step that resolves to nobody (§9 Q2 = A) ───────────────────────

    /**
     * Step 1 (headA) resolves; step 2 does not, and the whole submission is
     * refused. A named post does not climb: the route named HR on purpose,
     * and the general manager above it is not a substitute for مدير الموارد.
     */
    public function test_a_vacant_named_post_refuses_the_submission_and_names_it(): void
    {
        $this->units['hr']->update(['manager_id' => null]);

        $this->assertRefused(
            fn () => $this->submit($this->people['staff']),
            'لا يمكن تقديم الطلب: منصب مدير «إدارة الموارد البشرية» شاغر.',
        );
    }

    public function test_a_named_post_held_by_a_departed_employee_is_vacant(): void
    {
        $this->people['hrHead']->delete();

        $this->assertRefused(
            fn () => $this->submit($this->people['staff']),
            'لا يمكن تقديم الطلب: منصب مدير «إدارة الموارد البشرية» شاغر.',
        );
    }

    public function test_an_unknown_unit_code_refuses_the_submission(): void
    {
        $route = ApprovalRoute::sequential(RejectionPolicy::Ends, StepDefinition::unitManager('NOPE'));

        $this->assertRefused(
            fn () => $this->submit($this->people['staff'], $route),
            'لا يمكن تقديم الطلب: الوحدة ذات الرمز «NOPE» غير موجودة.',
        );
    }

    public function test_a_chain_climbing_to_an_empty_general_managers_post_refuses_the_submission(): void
    {
        // headA's own leave: past themselves, to GM — whose post is empty.
        $this->units['gm']->update(['manager_id' => null]);

        $this->assertRefused(
            fn () => $this->submit($this->people['headA']),
            'لا يمكن تقديم الطلب: لا يوجد مدير مباشر في الهيكل التنظيمي لاعتماده.',
        );
    }

    // ── An approver with no active account (§9 Q8 = A) ───────────────────

    public function test_an_approver_whose_account_is_disabled_refuses_the_submission(): void
    {
        $this->accountOf($this->people['headA'])->update(['is_active' => false]);
        $name = $this->people['headA']->full_name;

        $this->assertRefused(
            fn () => $this->submit($this->people['staff']),
            "لا يمكن تقديم الطلب: المعتمد «{$name}» ليس لديه حساب فعّال.",
        );
    }

    public function test_an_approver_with_no_account_at_all_refuses_the_submission(): void
    {
        $noLogin = $this->person($this->units['a1'], withAccount: false);
        $name = $noLogin->full_name;

        $this->assertRefused(
            fn () => $this->submit($this->people['staff'], $this->namedRoute(RejectionPolicy::Ends, $noLogin)),
            "لا يمكن تقديم الطلب: المعتمد «{$name}» ليس لديه حساب فعّال.",
        );
    }

    public function test_a_skipped_step_needs_no_account(): void
    {
        // Step 2 repeats step 1 and is skipped; only step 1's approver must
        // be able to act.
        $x = $this->person();
        $approval = $this->submit($this->people['staff'], $this->namedRoute(RejectionPolicy::Ends, $x, $x));

        $this->assertSame([StepStatus::Pending, StepStatus::Skipped], $approval->steps->pluck('status')->all());
    }

    // ── Frozen at submission (§9 Q3 = A) ─────────────────────────────────

    public function test_the_chain_does_not_move_when_the_org_chart_changes(): void
    {
        $approval = $this->submit($this->people['staff']);

        $newHead = $this->person($this->units['a']);
        $this->units['a']->update(['manager_id' => $newHead->id]);

        $this->assertSame($this->people['headA']->id, (int) $approval->steps()->where('step_order', 1)->value('approver_id'));

        // The new manager cannot act on it; the one it was given still can.
        try {
            $this->engine()->act($approval->steps()->where('step_order', 1)->sole(), $this->accountOf($newHead), Decision::Approve);
            $this->fail('A manager appointed after submission must not hold the step.');
        } catch (ApprovalException $e) {
            $this->assertSame('ليس دورك في اعتماد هذا الطلب.', $e->getMessage());
        }

        $this->decide($approval, $this->people['headA']);
        $this->assertSame(2, $approval->fresh('steps')->currentOrder());
    }

    // ── Routes ───────────────────────────────────────────────────────────

    public function test_parallel_steps_share_an_order(): void
    {
        [$m1, $m2, $chief] = [$this->person(), $this->person(), $this->person()];

        $route = ApprovalRoute::stages(
            RejectionPolicy::Advisory,
            [StepDefinition::namedEmployee($m1->id), StepDefinition::namedEmployee($m2->id)],
            [StepDefinition::namedEmployee($chief->id)->decisive()],
        );

        $approval = $this->submit($this->people['staff'], $route);

        $this->assertSame([1, 1, 2], $approval->steps->pluck('step_order')->all());
        $this->assertSame([false, false, true], $approval->steps->pluck('decisive')->all());

        // Both parallel approvers are told at once; the chief is not yet.
        $this->assertCount(1, $this->notificationsOf($m1));
        $this->assertCount(1, $this->notificationsOf($m2));
        $this->assertSame([], $this->notificationsOf($chief));
    }

    public function test_an_advisory_route_must_end_with_one_decisive_step(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ApprovalRoute::sequential(RejectionPolicy::Advisory, StepDefinition::directManager());
    }

    public function test_only_the_last_step_of_an_advisory_route_may_be_decisive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ApprovalRoute::sequential(
            RejectionPolicy::Advisory,
            StepDefinition::directManager()->decisive(),
            StepDefinition::unitManager('HR')->decisive(),
        );
    }

    public function test_two_parallel_steps_cannot_name_the_same_person(): void
    {
        $m = $this->person();
        $route = ApprovalRoute::stages(
            RejectionPolicy::Advisory,
            [StepDefinition::namedEmployee($m->id), StepDefinition::namedEmployee($m->id)],
            [StepDefinition::namedEmployee($this->people['gm']->id)->decisive()],
        );

        $this->assertRefused(fn () => $this->submit($this->people['staff'], $route), 'لا يمكن تكرار المعتمد نفسه في الخطوة ذاتها.');
    }

    public function test_every_engine_row_is_audited(): void
    {
        $approval = $this->submit($this->people['staff']);

        $this->assertDatabaseHas('audit_logs', ['auditable_type' => 'approval', 'auditable_id' => $approval->id, 'event' => 'created']);
        $this->assertSame(2, AuditLog::where('auditable_type', 'approval_step')->where('event', 'created')->count());
    }
}
