<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Attendance\Enums\AttendanceRecordStatus;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\NotificationService;
use App\Shared\Support\NotificationEventCatalogue;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The shift clock (work shifts spec §8): run every five minutes, it does for
 * each open check-in what the 17:00 reminder and the 23:59 close did for
 * everyone at once.
 *
 * - Reminder: once the duty's end has passed — legacy's words, once per
 *   check-in (`checkout_reminded_at`), so a missed or repeated run neither
 *   skips nor repeats it. An outside-schedule check-in is not reminded. The
 *   open day (build steps 3–4) is reminded at the old reminder_at time.
 * - Close: once the check-in's close has passed, a missing check-out — no
 *   invented check-out, no hours, nobody notified.
 *
 * A refused check-in is out of it, as legacy. Both writes are in bulk, as
 * the old close's was, so they add no audit row per record: the row itself
 * says what happened.
 */
class AttendanceShiftClock
{
    /** A duty closes at most four days after its shift date. */
    private const LOOKBACK_DAYS = 4;

    public function __construct(
        private readonly AttendanceCalendar $calendar,
        private readonly OpenCheckIns $openCheckIns,
        private readonly NotificationService $notifications,
    ) {}

    /** @return array{reminded: int, closed: int} */
    public function run(CarbonInterface $at): array
    {
        $at = CarbonImmutable::instance($at)->setTimezone($this->calendar->timezone());

        $toClose = [];
        $toRemind = [];
        foreach ($this->open($at) as $checkIn) {
            if ($this->openCheckIns->closeOf($checkIn)->lte($at)) {
                $toClose[] = $checkIn->id;

                continue;
            }

            $remindAt = $this->remindAt($checkIn);
            if ($remindAt !== null && $checkIn->checkout_reminded_at === null && $remindAt->lte($at)) {
                $toRemind[] = $checkIn;
            }
        }

        if ($toClose !== []) {
            AttendanceRecord::query()->whereKey($toClose)->update(['status' => AttendanceRecordStatus::MissingCheckout]);
        }

        if ($toRemind !== []) {
            $accounts = User::query()->whereIn('employee_id', array_map(fn (AttendanceRecord $r): int => (int) $r->employee_id, $toRemind))->pluck('id')->all();
            $this->notifications->sendToUsers(
                $accounts,
                'تذكير بتسجيل الانصراف',
                'لقد سجلت حضورك اليوم ولم تسجل انصرافك بعد. يرجى تسجيل الانصراف.',
                type: NotificationEventCatalogue::ATTENDANCE_CHECKOUT_REMINDER,
            );
            AttendanceRecord::query()->whereKey(array_map(fn (AttendanceRecord $r): int => $r->id, $toRemind))
                ->update(['checkout_reminded_at' => $at]);
        }

        return ['reminded' => count($toRemind), 'closed' => count($toClose)];
    }

    /** When a still-open check-in is reminded; null when it never is. */
    private function remindAt(AttendanceRecord $checkIn): ?CarbonImmutable
    {
        $duty = $this->openCheckIns->dutyOf($checkIn);
        if ($duty === null) {
            return null;
        }

        if ($duty->isOpen) {
            [$hour, $minute] = array_map('intval', explode(':', $this->calendar->reminderAt()));

            return $duty->startsAt()->setTime($hour, $minute);
        }

        return $duty->endsAt();
    }

    /**
     * Accepted check-ins with no accepted check-out and no flag yet.
     *
     * @return Collection<int, AttendanceRecord>
     */
    private function open(CarbonImmutable $at): Collection
    {
        return AttendanceRecord::query()
            ->with('employee')
            ->checkIns()->accepted()->whereNull('status')
            ->inDateRange($at->subDays(self::LOOKBACK_DAYS)->toDateString(), $at->toDateString())
            ->whereNotExists(fn ($out) => $out->selectRaw('1')
                ->from('attendance_records as outs')
                ->whereColumn('outs.employee_id', 'attendance_records.employee_id')
                ->whereColumn('outs.shift_date', 'attendance_records.shift_date')
                ->where('outs.type', AttendanceEventType::CheckOut->value)
                ->whereNull('outs.rejected_at')
                ->whereNull('outs.deleted_at'))
            ->get();
    }
}
