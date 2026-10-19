<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\NotificationService;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Exceptions\LeaveException;
use App\Modules\Leave\Models\LeaveRequest;
use App\Shared\Support\NotificationEventCatalogue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The employee came back before their approved leave was over — legacy's
 * LeaveRequestController::returnToWork() (LEAVE_BUILD.md §6).
 *
 * The days taken stand; the leave is cut back to end the day before the
 * return, and everything downstream follows from those two values: the
 * check-in guard and every attendance report read the span, and the
 * recounted `requested_days` hands the unused days back to the balance.
 * Back on the first day there is no shorter leave to keep, so it is
 * cancelled instead; `requested_days` then stays what was asked for.
 *
 * THE APPROVAL IS UNTOUCHED: a manager granted the leave, and that decision
 * stands for every day it covered. What HR records here is an attendance
 * fact. `original_end_date` is written once, so a leave shortened twice keeps
 * the span that was granted.
 */
class ReturnToWorkService
{
    public function __construct(
        private readonly AttendanceCalendar $calendar,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @throws LeaveException
     */
    public function record(LeaveRequest $leave, User $by, ?string $returnedOn, ?string $reason): ReturnOutcome
    {
        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);

        $outcome = DB::transaction(function () use ($leave, $by, $returnedOn, $reason): ReturnOutcome {
            // Locked before the dates are read, so two clicks cannot both
            // shorten the original span.
            /** @var LeaveRequest $leave */
            $leave = LeaveRequest::query()->whereKey($leave->id)->lockForUpdate()->firstOrFail();

            if ($leave->status !== LeaveStatus::Approved) {
                throw new LeaveException('يمكن إنهاء الإجازة المعتمدة فقط بالعودة إلى العمل.');
            }

            // An excuse covers an absence that already happened: there is no
            // remainder to give back, and "they came to work" means the entry
            // was wrong — which is what its undo is for.
            if ($leave->isExcuse()) {
                throw new LeaveException('هذا عذر غياب سجّلته الموارد البشرية، وليس إجازة جارية. تراجع عنه بدلاً من ذلك.');
            }

            $returned = Carbon::parse($returnedOn ?? $this->calendar->today())->startOfDay();
            $start = $leave->start_date->copy()->startOfDay();

            if ($returned->lt($start) || $returned->gt($leave->end_date->copy()->startOfDay())) {
                throw new LeaveException('يجب أن يقع تاريخ العودة ضمن فترة الإجازة.');
            }

            $originalEnd = ($leave->original_end_date ?? $leave->end_date)->copy();
            $newEnd = $returned->copy()->subDay();

            if ($newEnd->lt($start)) {
                $leave->update([
                    'status'              => LeaveStatus::Cancelled,
                    'cancelled_at'        => now(),
                    'cancelled_by'        => $by->getKey(),
                    'cancellation_reason' => $reason,
                    'returned_to_work_on' => $returned->toDateString(),
                    'original_end_date'   => $originalEnd->toDateString(),
                ]);
            } else {
                $leave->update([
                    'end_date'            => $newEnd->toDateString(),
                    'requested_days'      => $this->calendar->workingDaysBetween($start, $newEnd),
                    'returned_to_work_on' => $returned->toDateString(),
                    'original_end_date'   => $originalEnd->toDateString(),
                    'shortened_at'        => now(),
                    'shortened_by'        => $by->getKey(),
                    'shortening_reason'   => $reason,
                ]);
            }

            $cancelled = $leave->status === LeaveStatus::Cancelled;
            $daysReturned = $leave->deducts_balance
                ? max(0, $this->calendar->workingDaysBetween($start, $originalEnd) - ($cancelled ? 0 : $leave->requested_days))
                : 0;

            return new ReturnOutcome($leave, $cancelled, $daysReturned);
        });

        $this->tell($outcome, $reason);

        return $outcome;
    }

    /**
     * Told for the reason an excuse's undo is: their balance moved and their
     * record changed without them asking — good news here, but a balance
     * that grows unannounced confuses as much as one that shrinks (legacy).
     */
    private function tell(ReturnOutcome $outcome, ?string $reason): void
    {
        $leave = $outcome->leave;
        $type = $leave->type->label();
        $returned = $leave->returned_to_work_on->toDateString();
        $original = $leave->original_end_date->toDateString();

        $body = $outcome->cancelled
            ? "تم إلغاء ({$type}) المعتمدة من {$leave->start_date->toDateString()} إلى {$original} لعودتك إلى العمل بتاريخ {$returned}."
            : "تم إنهاء ({$type}) المعتمدة بتاريخ {$leave->end_date->toDateString()} بدلاً من {$original} لعودتك إلى العمل بتاريخ {$returned}.";

        $body .= ($outcome->daysReturned > 0 ? " وأُعيد إلى رصيدك {$outcome->daysReturned} يوم." : '')
            .($reason !== null ? " السبب: {$reason}" : '');

        $account = User::query()->where('employee_id', $leave->employee_id)->value('id');

        if ($account !== null) {
            $this->notifications->sendToUser((int) $account, 'تم تعديل إجازتك لعودتك إلى العمل', $body, type: NotificationEventCatalogue::LEAVE_RETURNED_TO_WORK, reference: $leave);
        }
    }
}
