<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Attendance\Enums\AttendanceSource;
use App\Modules\Attendance\Enums\RejectionReason;
use App\Modules\Attendance\Exceptions\AttendanceException;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\NotificationService;
use App\Modules\HR\Models\Employee;
use App\Shared\Support\NotificationEventCatalogue;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What HR and managers do to someone else's attendance — legacy's
 * correctCheckout, reject and unreject, with their rules and messages
 * (work order §4.5 slice 2; docs/ATTENDANCE_BUILD.md).
 *
 * Who may act on which row is decided before this runs, by the route's
 * `can:` (AttendanceRecordPolicy). These methods hold only what makes the
 * action valid.
 *
 * Each one takes the same lock recording takes — the employee's row — because
 * each reads the day and then writes it: without it a check-out committing in
 * between would survive beside a refused check-in, or beside a correction.
 */
class AttendanceOversightService
{
    public function __construct(
        private readonly AttendanceCalendar $calendar,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Record the check-out the employee forgot.
     *
     * The check-in is left as it was. A check-out row is written with the
     * corrector, the time, the note and the day's hours, and the check-in's
     * missing-check-out flag is cleared. The 60-minute guard does not apply:
     * this is an authoritative time, not a tap.
     *
     * @return array{check_in: AttendanceRecord, check_out: AttendanceRecord}
     *
     * @throws AttendanceException
     */
    public function correctCheckout(AttendanceRecord $checkIn, User $corrector, string $checkedOutAt, ?string $note): array
    {
        if ($checkIn->type !== AttendanceEventType::CheckIn) {
            throw new AttendanceException('يجب اختيار سجل دخول لتصحيح الانصراف.');
        }

        return DB::transaction(function () use ($checkIn, $corrector, $checkedOutAt, $note): array {
            $this->lock($checkIn);
            AttendanceException::unlessOnDuty($checkIn->employee);

            // The check-in must still stand, and its day have no check-out yet.
            $date = $checkIn->recorded_at->toDateString();
            $day = AttendanceRecord::forEmployee((int) $checkIn->employee_id)->accepted()->onDate($date);
            if ($checkIn->refresh()->isRejected() || (clone $day)->checkOuts()->exists()) {
                throw new AttendanceException('هذا السجل لا يحتاج إلى تصحيح انصراف.');
            }

            $moment = $this->calendar->parse($checkedOutAt);
            if ($moment->lte($checkIn->recorded_at)) {
                throw new AttendanceException('وقت الانصراف يجب أن يكون بعد وقت الحضور.');
            }

            // On the check-in's own day, and not yet to come: a typo would
            // otherwise file it on another date, leaving the day it corrects
            // open (parity audit S-3; legacy took any later moment).
            if ($moment->toDateString() !== $date) {
                throw new AttendanceException('وقت الانصراف يجب أن يكون في يوم الحضور نفسه.');
            }
            if ($moment->isFuture()) {
                throw new AttendanceException('وقت الانصراف لا يمكن أن يكون في المستقبل.');
            }

            $checkOut = AttendanceRecord::create([
                'employee_id'     => $checkIn->employee_id,
                'type'            => AttendanceEventType::CheckOut,
                'source'          => AttendanceSource::HrCorrection,
                'recorded_at'     => $moment,
                'work_hours'      => round($checkIn->recorded_at->floatDiffInRealHours($moment), 2),
                'latitude'        => $checkIn->latitude,
                'longitude'       => $checkIn->longitude,
                'selfie_path'     => null,
                'corrected_by'    => $corrector->getKey(),
                'corrected_at'    => Carbon::now($this->calendar->timezone()),
                'correction_note' => $note,
            ]);

            // The check-in is no longer an orphan.
            $checkIn->update(['status' => null]);

            return ['check_in' => $checkIn->refresh(), 'check_out' => $checkOut];
        });
    }

    /**
     * Refuse an event HR does not accept — recorded away from the work site,
     * or with a selfie that is not the employee — and tell the employee why.
     *
     * The row is kept as the evidence; every rule and report now looks
     * through it, so the day counts as absent and the employee may record it
     * again. Refusing a check-in refuses that day's check-out with it: its
     * hours would otherwise count for a day with no check-in. Every row this
     * one decision takes down shares one batch, which is what lets an undo
     * restore exactly them.
     *
     * The notification is written inside the same transaction, so a refusal
     * and the employee's notice stand or fall together; its push goes out only
     * after commit (NotificationService).
     *
     * @return array<int, AttendanceRecord> every row refused, the given one first
     *
     * @throws AttendanceException when it was already refused
     */
    public function reject(AttendanceRecord $record, User $reviewer, RejectionReason $reason, ?string $note): array
    {
        return DB::transaction(function () use ($record, $reviewer, $reason, $note): array {
            $this->lock($record);
            $record->refresh();
            AttendanceException::unlessOnDuty($record->employee);

            if ($record->isRejected()) {
                throw new AttendanceException('تم رفض هذا السجل مسبقاً.');
            }

            $stamp = [
                'rejected_at'      => Carbon::now($this->calendar->timezone()),
                'rejected_by'      => $reviewer->getKey(),
                'rejection_reason' => $reason,
                'rejection_note'   => $note,
                'rejection_batch'  => (string) Str::uuid(),
            ];

            $record->update($stamp);
            $rejected = [$record];

            if ($record->type === AttendanceEventType::CheckIn) {
                $pairs = AttendanceRecord::forEmployee((int) $record->employee_id)
                    ->checkOuts()
                    ->accepted()
                    ->onDate($record->recorded_at->toDateString())
                    ->get();

                foreach ($pairs as $pair) {
                    $pair->update($stamp);
                    $rejected[] = $pair;
                }
            }

            $this->notifyRefusal($record, $reason, $note);

            return $rejected;
        });
    }

    /**
     * Undo a refusal — HR refused the wrong row, or the employee produced the
     * missing justification. The whole decision comes back, and only it.
     *
     * No notification, as legacy: the employee was told the day was refused,
     * and its counting again is not news they must act on.
     *
     * @return array<int, AttendanceRecord> every row restored, the decision's check-in first
     *
     * @throws AttendanceException when the row is not refused, or restoring it
     *                             would collide with the day as re-recorded
     */
    public function unreject(AttendanceRecord $record): array
    {
        return DB::transaction(function () use ($record): array {
            $this->lock($record);
            $record->refresh();
            AttendanceException::unlessOnDuty($record->employee);

            if (! $record->isRejected()) {
                throw new AttendanceException('هذا السجل غير مرفوض.');
            }

            $employeeId = (int) $record->employee_id;

            // A check-out refused WITH its check-in is one member of that
            // decision: undoing it from the check-out row restores the decision
            // from its check-in, so no accepted check-out is left beside a
            // refused check-in. A check-out refused on its own has no check-in
            // in its batch and is restored alone.
            if ($record->type === AttendanceEventType::CheckOut && $record->rejection_batch !== null) {
                $record = AttendanceRecord::forEmployee($employeeId)->checkIns()->rejected()
                    ->where('rejection_batch', $record->rejection_batch)->first() ?? $record;
            }

            $date = $record->recorded_at->toDateString();
            $isCheckIn = $record->type === AttendanceEventType::CheckIn;
            $day = AttendanceRecord::forEmployee($employeeId)->accepted()->onDate($date);

            // The employee usually re-recorded the day after being told. Putting
            // the refused rows back on top would break one-shift-a-day: two open
            // check-ins the reports cannot pair, or two check-outs whose hours
            // are summed twice. Refused rather than silently corrupted.
            if ($isCheckIn && (clone $day)->checkIns()->exists()) {
                throw new AttendanceException('لا يمكن التراجع عن الرفض: يوجد تسجيل حضور قائم لهذا اليوم بالفعل.');
            }

            // The rest of the decision, matched on the batch, never on the date:
            // a day can carry several separate refusals, and undoing one must not
            // resurrect a check-out another decision refused on its own grounds.
            $pairs = $isCheckIn && $record->rejection_batch !== null
                ? AttendanceRecord::forEmployee($employeeId)->checkOuts()->rejected()
                    ->where('rejection_batch', $record->rejection_batch)->whereKeyNot($record->getKey())->get()
                : collect();

            if ((! $isCheckIn || $pairs->isNotEmpty()) && (clone $day)->checkOuts()->exists()) {
                throw new AttendanceException('لا يمكن التراجع عن الرفض: يوجد تسجيل انصراف قائم لهذا اليوم بالفعل.');
            }

            $clear = [
                'rejected_at'      => null,
                'rejected_by'      => null,
                'rejection_reason' => null,
                'rejection_note'   => null,
                'rejection_batch'  => null,
            ];

            $record->update($clear);
            $restored = [$record];

            foreach ($pairs as $pair) {
                $pair->update($clear);
                $restored[] = $pair;
            }

            return $restored;
        });
    }

    private function lock(AttendanceRecord $record): void
    {
        Employee::withTrashed()->whereKey($record->employee_id)->lockForUpdate()->first();
    }

    /**
     * Legacy's wording, so the app, the board and the reports never disagree
     * about what was wrong. An employee with no account is not notified — there
     * is no one to receive it — and the refusal stands.
     */
    private function notifyRefusal(AttendanceRecord $record, RejectionReason $reason, ?string $note): void
    {
        $account = User::where('employee_id', $record->employee_id)->first();
        if ($account === null) {
            return;
        }

        $event = $record->type === AttendanceEventType::CheckIn ? 'حضورك' : 'انصرافك';
        $body = "لم يتم قبول تسجيل {$event} بتاريخ {$record->recorded_at->toDateString()}: {$reason->label()}.";
        if ($note !== null && $note !== '') {
            $body .= " ملاحظة: {$note}";
        }

        $this->notifications->sendToUser(
            $account,
            'لم يتم قبول تسجيل حضورك',
            $body,
            type: NotificationEventCatalogue::ATTENDANCE_RECORD_REJECTED,
            reference: $record,
        );
    }
}
