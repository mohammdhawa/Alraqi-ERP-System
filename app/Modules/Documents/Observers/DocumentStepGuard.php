<?php

declare(strict_types=1);

namespace App\Modules\Documents\Observers;

use App\Modules\Approvals\Enums\StepStatus;
use App\Modules\Approvals\Exceptions\ApprovalException;
use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Services\DecisionGate;
use App\Modules\Documents\Services\UnitManagers;
use App\Modules\HR\Models\Employee;

/**
 * Keeps a document's approval steps out of the general approvals endpoints'
 * reach (documents design §7.3). The engine saves every step through
 * `update()`, so one `updating` hook sees every decision and reassignment
 * without the engine knowing documents exist.
 */
final class DocumentStepGuard
{
    public function __construct(
        private readonly DecisionGate $gate,
        private readonly UnitManagers $managers,
    ) {}

    public function updating(ApprovalStep $step): void
    {
        if ($step->approval?->approvable_type !== (new Document)->getMorphClass()) {
            return;
        }

        if ($this->isDecision($step) && ! $this->gate->isOpen((int) $step->getKey())) {
            throw new ApprovalException('يُتَّخذ القرار في المستندات من شاشة المستند.');
        }

        if ($step->isDirty('approver_id')) {
            $this->guardReassignment($step);
        }
    }

    private function isDecision(ApprovalStep $step): bool
    {
        if (! $step->isDirty('status')) {
            return false;
        }

        $from = $step->getOriginal('status');
        $from = $from instanceof StepStatus ? $from : StepStatus::tryFrom((int) $from);

        return $from === StepStatus::Pending
            && in_array($step->status, [StepStatus::Approved, StepStatus::Rejected], true);
    }

    private function guardReassignment(ApprovalStep $step): void
    {
        if (auth()->user()?->isSuperAdmin() !== true) {
            throw new ApprovalException('إعادة إسناد خطوات المستندات لمدير النظام فقط.', 403);
        }

        $to = Employee::query()->find($step->approver_id);

        if ($to === null || $this->managers->unitOf($to) === null) {
            throw new ApprovalException('لا يمكن الإسناد إلا إلى مدير وحدة تنظيمية.');
        }

        $toGeneralManager = $this->managers->generalManager()?->getKey() === $to->getKey();

        if ($step->decisive && ! $toGeneralManager) {
            throw new ApprovalException('خطوة القرار الأخير لا تُسند إلا إلى المدير العام الحالي.');
        }

        // Spec §5: approvers are never المدير العام. Holding a named step,
        // he could never be given the decider's (one step per person).
        if (! $step->decisive && $toGeneralManager) {
            throw new ApprovalException('المدير العام لا يُسند إليه إلا خطوة القرار الأخير.');
        }
    }
}
