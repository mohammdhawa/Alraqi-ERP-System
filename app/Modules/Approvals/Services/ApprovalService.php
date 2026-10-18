<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Services;

use App\Modules\Approvals\Contracts\Approvable;
use App\Modules\Approvals\Enums\ApprovalStatus;
use App\Modules\Approvals\Enums\ApproverNotice;
use App\Modules\Approvals\Enums\Decision;
use App\Modules\Approvals\Enums\RejectionPolicy;
use App\Modules\Approvals\Enums\SkipReason;
use App\Modules\Approvals\Enums\StepStatus;
use App\Modules\Approvals\Exceptions\ApprovalException;
use App\Modules\Approvals\Models\Approval;
use App\Modules\Approvals\Models\ApprovalReassignment;
use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Approvals\Support\ApprovalRoute;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\NotificationService;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use App\Shared\Support\NotificationEventCatalogue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The approvals engine (work order §4.6; docs/APPROVALS_ENGINE_DESIGN.md).
 *
 * One engine for every consumer, built from legacy's two: leave's sequential
 * chain where a rejection ends it, and documents' sequential-or-parallel
 * chain where a manager's rejection is advisory and the chief decides. The
 * difference is the approval's `rejection_policy`, fixed at submission.
 *
 * WHO MAY CALL WHAT is decided before this runs — the routes' policies, step
 * 2. These methods hold what makes the action valid, and the one rule that
 * is not a permission: you act on a step because you are its approver.
 * Being an administrator does not make it your turn — legacy's rule.
 *
 * LOCKS, legacy's order: the approval row first, then its steps. Two
 * approvers acting at once, an approval racing a reassignment, a withdrawal
 * racing a decision — each serialises on the approval.
 *
 * THE SUBJECT IS TOLD INSIDE THE TRANSACTION. `onApprovalDecided()` is the
 * only writer of the subject's status, and running it in the same
 * transaction is what makes "the approval says approved, the leave says
 * pending" impossible (P2). Notification rows are written in it too, as the
 * attendance module does, so a rolled-back decision notifies nobody.
 */
class ApprovalService
{
    public function __construct(
        private readonly ApproverResolver $resolver,
        private readonly NotificationService $notifications,
    ) {}

    // ── Submitting ───────────────────────────────────────────────────────

    /**
     * Resolve every step now, apply the skip rules (decision round Q2), save,
     * and notify the first approver(s).
     *
     * Frozen from here (§9 Q3 = A): a later change to the org chart moves no
     * pending step; reassignment is the fix.
     *
     * @throws ApprovalException
     */
    public function submit(Model&Approvable $subject, ApprovalRoute $route): Approval
    {
        return DB::transaction(function () use ($subject, $route): Approval {
            $exists = Approval::withTrashed()
                ->where('approvable_type', $subject->getMorphClass())
                ->where('approvable_id', $subject->getKey())
                ->exists();

            if ($exists) {
                throw new ApprovalException('هذا الطلب مُقدَّم للاعتماد مسبقاً.');
            }

            $requester = $subject->approvalRequester();
            $planned = $this->plan($route, $requester);

            $approval = Approval::create([
                'approvable_type'  => $subject->getMorphClass(),
                'approvable_id'    => $subject->getKey(),
                'requested_by'     => $requester->getKey(),
                'status'           => ApprovalStatus::Pending,
                'rejection_policy' => $route->policy,
            ]);

            foreach ($planned as $attributes) {
                $approval->steps()->create($attributes);
            }

            $approval->load('steps');
            $this->notifyCurrent($approval, $subject, ApproverNotice::Submitted);

            return $approval;
        });
    }

    /**
     * The steps to store, in order. Every definition is resolved before any
     * rule is applied, so a vacant post anywhere on the route refuses the
     * submission (§9 Q2) even if an earlier step would have been skipped.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ApprovalException
     */
    private function plan(ApprovalRoute $route, Employee $requester): array
    {
        $resolved = [];

        foreach ($route->stages as $index => $stage) {
            foreach ($stage as $definition) {
                $resolved[$index][] = [$definition, $this->resolver->resolve($definition, $requester)];
            }
        }

        $planned = [];

        // The approvers of the nearest earlier stage that still has someone
        // to ask — "the step before it" (Q2). A stage skipped entirely is not
        // a step anyone stands on, so the comparison looks past it.
        $before = [];

        foreach ($resolved as $index => $stage) {
            $here = [];

            foreach ($stage as [$definition, $approver]) {
                $id = (int) $approver->getKey();

                if (isset($here[$id])) {
                    throw new ApprovalException('لا يمكن تكرار المعتمد نفسه في الخطوة ذاتها.');
                }

                $skip = match (true) {
                    $id === (int) $requester->getKey() => SkipReason::Requester,
                    isset($before[$id])                => SkipReason::SameApprover,
                    default                            => null,
                };

                if ($skip === null) {
                    $this->resolver->ensureCanAct($approver);
                    $here[$id] = true;
                }

                $planned[] = [
                    'step_order'   => $index + 1,
                    'resolver'     => $definition->resolver,
                    'resolver_ref' => $definition->ref,
                    'approver_id'  => $id,
                    'decisive'     => $definition->decisive,
                    'status'       => $skip === null ? StepStatus::Pending : StepStatus::Skipped,
                    'skip_reason'  => $skip,
                ];
            }

            if ($here !== []) {
                $before = $here;
            }
        }

        $anyPending = array_filter($planned, static fn (array $step): bool => $step['status'] === StepStatus::Pending);

        if ($anyPending === []) {
            throw new ApprovalException('لا يمكن تقديم الطلب: لا يوجد من يعتمده.');
        }

        return $planned;
    }

    // ── Acting ───────────────────────────────────────────────────────────

    /**
     * Approve or reject one step (design §5).
     *
     * @throws ApprovalException
     */
    public function act(ApprovalStep $step, User $actor, Decision $decision, ?string $note = null): Approval
    {
        return DB::transaction(function () use ($step, $actor, $decision, $note): Approval {
            $approval = $this->lockPending($step->approval_id, 'يمكن اتخاذ القرار في الطلبات قيد الاعتماد فقط.');
            $step = $this->stepOf($approval, $step);

            // Whose turn: pending, at the current order, and the caller's
            // account linked to the step's person.
            $isTurn = $step->isPending()
                && $step->step_order === $approval->currentOrder()
                && $actor->employee_id !== null
                && (int) $actor->employee_id === (int) $step->approver_id;

            if (! $isTurn) {
                throw new ApprovalException('ليس دورك في اعتماد هذا الطلب.');
            }

            /** @var Model&Approvable $subject */
            $subject = $approval->approvable;

            $ends = $decision === Decision::Reject
                && ($approval->rejection_policy === RejectionPolicy::Ends || $step->decisive);

            $others = $approval->steps->filter(
                static fn (ApprovalStep $other): bool => $other->isPending() && $other->isNot($step),
            );

            // The final-approval guard: asked before the approval that would
            // complete it is recorded, so a refusal changes nothing.
            if (! $ends && $others->isEmpty()) {
                $refusal = $subject->canBeApproved();

                if ($refusal !== null) {
                    throw new ApprovalException($refusal);
                }
            }

            $step->update([
                'status'   => $decision->stepStatus(),
                'acted_by' => $actor->getKey(),
                'acted_at' => now(),
                'note'     => $note === null || trim($note) === '' ? null : trim($note),
            ]);

            if ($ends) {
                $this->close($approval, $others, ApprovalStatus::Rejected, SkipReason::ClosedByRejection, $subject);
            } elseif ($others->isEmpty()) {
                $this->close($approval, $others, ApprovalStatus::Approved, null, $subject);
            } elseif ($approval->currentOrder() !== $step->step_order) {
                // The last step of its order decided: the next order is current.
                $this->notifyCurrent($approval, $subject, ApproverNotice::YourTurn);
            }

            return $approval->fresh('steps');
        });
    }

    // ── Withdrawing (§9 Q1 = B) ──────────────────────────────────────────

    /**
     * The requester takes back a request nobody has decided yet. A step
     * skipped at submission is not a decision. Legacy has no withdrawal.
     *
     * @throws ApprovalException
     */
    public function withdraw(Approval $approval, User $actor): Approval
    {
        return DB::transaction(function () use ($approval, $actor): Approval {
            $approval = $this->lockPending($approval->getKey(), 'لا يمكن سحب طلب ليس قيد الاعتماد.');

            if ($actor->employee_id === null || (int) $actor->employee_id !== (int) $approval->requested_by) {
                throw new ApprovalException('لا يمكن سحب طلب لم تقدّمه.', Response::HTTP_FORBIDDEN);
            }

            if ($approval->steps->contains(static fn (ApprovalStep $step): bool => $step->status->isDecision())) {
                throw new ApprovalException('لا يمكن سحب الطلب بعد أن اتُّخذ فيه قرار.');
            }

            /** @var Model&Approvable $subject */
            $subject = $approval->approvable;
            $pending = $approval->steps->filter(static fn (ApprovalStep $step): bool => $step->isPending());

            $this->close($approval, $pending, ApprovalStatus::Cancelled, SkipReason::ClosedByCancellation, $subject);

            return $approval->fresh('steps');
        });
    }

    // ── Reassigning (design §6) ──────────────────────────────────────────

    /**
     * Hand a pending step to someone else — legacy's escape hatch for an
     * approver who can no longer act. It moves the assignment and never
     * decides.
     *
     * Who may call it is `approvals.reassign`, in reach (§9 Q4), checked by
     * the route. Who it may go to is here (§9 Q7 = A): any active employee
     * with an active account, not the requester, not already on the chain.
     *
     * @throws ApprovalException
     */
    public function reassign(ApprovalStep $step, Employee $to, User $performedBy, string $reason): ApprovalStep
    {
        $reason = trim($reason);
        $length = mb_strlen($reason);

        if ($length < 3 || $length > 500) {
            throw new ApprovalException('سبب إعادة الإسناد مطلوب، من 3 إلى 500 حرف.');
        }

        return DB::transaction(function () use ($step, $to, $performedBy, $reason): ApprovalStep {
            $approval = $this->lockPending($step->approval_id, 'يمكن إعادة إسناد الطلبات قيد الاعتماد فقط.');
            $step = $this->stepOf($approval, $step);
            $toId = (int) $to->getKey();

            if (! $step->isPending()) {
                throw new ApprovalException('يمكن إعادة إسناد خطوة بانتظار القرار فقط.');
            }

            if ($toId === (int) $step->approver_id) {
                throw new ApprovalException('هذه الخطوة مسندة إلى هذا الموظف أصلاً.');
            }

            if ($toId === (int) $approval->requested_by) {
                throw new ApprovalException('لا يمكن إسناد الاعتماد إلى مُقدِّم الطلب نفسه.');
            }

            // One approver decides a request once (legacy): any other step,
            // whatever its status, already makes them part of this chain.
            if ($approval->steps->contains(static fn (ApprovalStep $other): bool => $other->isNot($step) && (int) $other->approver_id === $toId)) {
                throw new ApprovalException('هذا الموظف يحمل خطوة أخرى في مسار اعتماد هذا الطلب.');
            }

            if (! $this->mayBeAssigned($to)) {
                throw new ApprovalException('لا يمكن الإسناد إلا إلى موظف نشط لديه حساب فعّال.');
            }

            $isCurrent = $step->step_order === $approval->currentOrder();
            $from = (int) $step->approver_id;

            $step->update(['approver_id' => $toId]);

            ApprovalReassignment::create([
                'approval_id'      => $approval->getKey(),
                'approval_step_id' => $step->getKey(),
                'step_order'       => $step->step_order,
                'from_employee_id' => $from,
                'to_employee_id'   => $toId,
                'performed_by'     => $performedBy->getKey(),
                'reason'           => $reason,
            ]);

            /** @var Model&Approvable $subject */
            $subject = $approval->approvable;
            $message = $subject->describeForApprover($isCurrent ? ApproverNotice::ReassignedNow : ApproverNotice::ReassignedAhead);

            $this->notifications->sendToUsers(
                $this->accountsOf([$toId]),
                $message->title,
                $message->body,
                type: $isCurrent ? NotificationEventCatalogue::APPROVAL_STEP_PENDING : NotificationEventCatalogue::APPROVAL_STEP_ASSIGNED,
                reference: $subject,
            );

            return $step->fresh();
        });
    }

    // ── Internals ────────────────────────────────────────────────────────

    /**
     * Lock the approval, then its steps, and refuse unless it is pending.
     *
     * @throws ApprovalException
     */
    private function lockPending(int $approvalId, string $refusal): Approval
    {
        /** @var Approval $approval */
        $approval = Approval::query()->whereKey($approvalId)->lockForUpdate()->firstOrFail();

        if (! $approval->isPending()) {
            throw new ApprovalException($refusal);
        }

        $approval->setRelation('steps', $approval->steps()->lockForUpdate()->get());

        return $approval;
    }

    /**
     * The step as it stands under the lock — the caller's copy may be stale.
     */
    private function stepOf(Approval $approval, ApprovalStep $step): ApprovalStep
    {
        return $approval->steps->firstWhere('id', $step->getKey())
            ?? throw new ApprovalException('هذه الخطوة لا تتبع هذا الطلب.');
    }

    /**
     * End the approval: close the steps nobody will be asked for, stamp the
     * outcome, and tell the subject.
     *
     * Each step is saved through its model rather than one bulk UPDATE, so
     * the audit trail records every closed step (C13) — a chain is a handful
     * of rows.
     *
     * @param  Collection<int, ApprovalStep>  $close
     */
    private function close(Approval $approval, Collection $close, ApprovalStatus $outcome, ?SkipReason $reason, Model&Approvable $subject): void
    {
        foreach ($close as $step) {
            $step->update(['status' => StepStatus::Skipped, 'skip_reason' => $reason]);
        }

        $approval->update(['status' => $outcome, 'decided_at' => now()]);

        $subject->onApprovalDecided($approval);
    }

    /**
     * Tell every approver whose step is now current. The reference is the
     * SUBJECT, so the tap opens the screen where they act.
     */
    private function notifyCurrent(Approval $approval, Model&Approvable $subject, ApproverNotice $notice): void
    {
        $order = $approval->currentOrder();

        if ($order === null) {
            return;
        }

        $approverIds = $approval->steps
            ->filter(static fn (ApprovalStep $step): bool => $step->isPending() && $step->step_order === $order)
            ->map(static fn (ApprovalStep $step): int => (int) $step->approver_id)
            ->values()
            ->all();

        $message = $subject->describeForApprover($notice);

        $this->notifications->sendToUsers(
            $this->accountsOf($approverIds),
            $message->title,
            $message->body,
            type: NotificationEventCatalogue::APPROVAL_STEP_PENDING,
            reference: $subject,
        );
    }

    /**
     * Q7: an active employee with an active account. Soft-deleted employees
     * are out by the global scope.
     */
    private function mayBeAssigned(Employee $to): bool
    {
        $live = Employee::query()->whereKey($to->getKey())->first();

        return $live !== null
            && $live->status === EmployeeStatus::Active
            && User::query()->where('employee_id', $live->getKey())->where('is_active', true)->exists();
    }

    /**
     * @param  array<int, int>  $employeeIds
     * @return array<int, int>
     */
    private function accountsOf(array $employeeIds): array
    {
        return $employeeIds === [] ? [] : User::query()
            ->whereIn('employee_id', $employeeIds)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
