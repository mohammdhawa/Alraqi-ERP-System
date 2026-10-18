<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Services;

use App\Modules\Approvals\Enums\ApprovalStatus;
use App\Modules\Approvals\Enums\StepStatus;
use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * What waits on an approver — the inbox the app and the dashboard both read
 * (design §7, `GET /api/me/approvals`). Builders, so the endpoint paginates
 * and eager-loads as it needs.
 *
 * Keyed on the caller's EMPLOYEE: a step names a person. An account with no
 * employee approves nothing, and gets an empty inbox rather than an error.
 */
class ApprovalInbox
{
    /**
     * Steps the caller can act on NOW: pending, of a pending approval, at
     * its current order. A later step on the same chain is not here until
     * its turn comes — legacy's queue showed it with `can_review: false`;
     * the live request screen still shows the whole chain.
     *
     * @return Builder<ApprovalStep>
     */
    public function pendingFor(User $user): Builder
    {
        return $this->mine($user)
            ->where('status', StepStatus::Pending)
            ->whereHas('approval', static fn (Builder $approval) => $approval->where('status', ApprovalStatus::Pending))
            ->where('step_order', '=', static function (QueryBuilder $current): void {
                $current->selectRaw('MIN(current_steps.step_order)')
                    ->from('approval_steps as current_steps')
                    ->whereColumn('current_steps.approval_id', 'approval_steps.approval_id')
                    ->where('current_steps.status', StepStatus::Pending->value)
                    ->whereNull('current_steps.deleted_at');
            })
            ->orderByDesc('approval_id')
            ->orderByDesc('id');
    }

    /**
     * Steps the caller decided, most recent decision first.
     *
     * @return Builder<ApprovalStep>
     */
    public function decidedBy(User $user): Builder
    {
        return $this->mine($user)
            ->whereIn('status', [StepStatus::Approved, StepStatus::Rejected])
            ->orderByDesc('acted_at')
            ->orderByDesc('id');
    }

    /**
     * @return Builder<ApprovalStep>
     */
    private function mine(User $user): Builder
    {
        $query = ApprovalStep::query();

        return $user->employee_id === null
            ? $query->whereRaw('1 = 0')
            : $query->where('approver_id', $user->employee_id);
    }
}
