<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Modules\Approvals\Enums\StepStatus;
use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Attendance\Contracts\ApprovedLeave;
use App\Modules\Attendance\Contracts\LeaveSpan;
use App\Modules\Auth\Models\User;
use App\Modules\Leave\Models\LeaveRequest;

/**
 * What attendance knows about leave, from the leave itself (LEAVE_BUILD.md
 * §4) — bound in place of NoApprovedLeave, the stand-in that told attendance
 * nobody was ever on leave.
 *
 * "Approved" is a request whose approval completed or an excuse HR filed;
 * a pending, rejected or cancelled one covers nothing. The span is the row's
 * as it stands now — a return to work that cut a leave short frees the days
 * after it, for the check-in guard and every report at once (legacy).
 *
 * Who approved is the account that took the LAST approved step — مدير
 * الموارد on a two-step chain; who recorded an excuse is its filer.
 */
class ApprovedLeaveFromRequests implements ApprovedLeave
{
    public function covers(int $employeeId, string $date): bool
    {
        return LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->approved()
            ->overlapping($date, $date)
            ->exists();
    }

    public function spans(array $employeeIds, string $from, string $to): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $rows = LeaveRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->approved()
            ->overlapping($from, $to)
            ->with(['type', 'createdBy.employee', 'approval.steps.actedBy.employee'])
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        return $rows->map(fn (LeaveRequest $leave): LeaveSpan => new LeaveSpan(
            leaveRequestId: (int) $leave->id,
            employeeId: (int) $leave->employee_id,
            startDate: $leave->start_date->toDateString(),
            endDate: $leave->end_date->toDateString(),
            isExcuse: $leave->isExcuse(),
            deductsBalance: $leave->deducts_balance,
            typeCode: $leave->type?->code,
            typeName: $leave->type?->name_ar,
            reason: $leave->reason,
            recordedBy: $leave->isExcuse() ? $this->nameOf($leave->createdBy) : null,
            approvedBy: $leave->isExcuse() ? null : $this->approverOf($leave),
        ))->all();
    }

    private function approverOf(LeaveRequest $leave): ?string
    {
        /** @var ApprovalStep|null $last */
        $last = $leave->approval?->steps
            ->filter(static fn (ApprovalStep $step): bool => $step->status === StepStatus::Approved)
            ->sortBy([['step_order', 'asc'], ['id', 'asc']])
            ->last();

        return $this->nameOf($last?->actedBy);
    }

    private function nameOf(?User $user): ?string
    {
        return $user === null ? null : ($user->employee?->full_name ?? $user->email);
    }
}
