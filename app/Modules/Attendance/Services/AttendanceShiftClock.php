<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Contracts\ExpectedShifts;
use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Attendance\Enums\AttendanceRecordStatus;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Modules\Attendance\Support\Duty;
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
 *   skips nor repeats it. An outside-schedule check-in is not reminded.
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
        private readonly ExpectedShifts $shifts,
        private readonly NotificationService $notifications,
    ) {}

    /** @return array{reminded: int, closed: int} */
    public function run(CarbonInterface $at): array
    {
        $at = CarbonImmutable::instance($at)->setTimezone($this->calendar->timezone());

        $open = $this->open($at);
        $duties = $this->dutiesOf($open, $at);

        $toClose = [];
        $toRemind = [];
        foreach ($open as $checkIn) {
            $shiftDate = $checkIn->shift_date->toDateString();
            $duty = $checkIn->outside_schedule ? null : ($duties[(int) $checkIn->employee_id][$shiftDate] ?? null);
            // A duty closes at its end plus the margin; a check-in outside the
            // schedule, or whose duty is gone, at the end of its shift date.
            $close = $duty?->closesAt ?? CarbonImmutable::parse($shiftDate, $this->calendar->timezone())->endOfDay()->startOfSecond();
            if ($close->lte($at)) {
                $toClose[] = $checkIn->id;

                continue;
            }

            $remindAt = $duty?->endsAt();
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

    /**
     * Every open check-in's possible duties, from ONE schedule read for all
     * their employees (final review I5): employee id → start date → duty.
     *
     * @param  Collection<int, AttendanceRecord>  $open
     * @return array<int, array<string, Duty>>
     */
    private function dutiesOf(Collection $open, CarbonImmutable $at): array
    {
        if ($open->isEmpty()) {
            return [];
        }

        $ids = $open->pluck('employee_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $shifts = $this->shifts->shiftsFor($ids, $at->subDays(self::LOOKBACK_DAYS + 6)->toDateString(), $at->addDay()->toDateString());
        $opens = max(0, (int) config('attendance.check_in_opens_minutes', 60));
        $closes = max(0, (int) config('attendance.close_after_minutes', 240));

        $duties = [];
        foreach ($shifts as $id => $byDate) {
            foreach (Duty::runs(array_values($byDate), $opens, $closes) as $duty) {
                $duties[$id][$duty->shiftDate()] = $duty;
            }
        }

        return $duties;
    }

    /**
     * Accepted check-ins with no accepted check-out and no flag yet.
     *
     * @return Collection<int, AttendanceRecord>
     */
    private function open(CarbonImmutable $at): Collection
    {
        return AttendanceRecord::query()
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
