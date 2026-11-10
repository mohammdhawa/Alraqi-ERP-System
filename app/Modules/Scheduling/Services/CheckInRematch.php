<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Services;

use App\Modules\Attendance\Contracts\ExpectedShifts;
use App\Modules\Attendance\Enums\AttendanceRecordStatus;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Modules\Attendance\Support\Duty;
use App\Modules\HR\Models\Employee;
use App\Modules\Scheduling\Exceptions\SchedulingException;
use Carbon\CarbonImmutable;

/**
 * Match the check-ins a change touches again, by the check-in rule, as if
 * they had happened under the new shifts (work shifts piece 2, spec §5.3).
 *
 * Called inside the caller's transaction, after the change is written or
 * removed. Each accepted check-in, in order of its moment, takes the first
 * duty holding that moment that no earlier one took; with none, it is
 * outside the schedule on its own date. Its check-out, paired by the old
 * shift date, moves with it. Anything the new shifts would have refused —
 * two check-ins on one duty or date, a check-out after the new close —
 * refuses the whole change.
 */
class CheckInRematch
{
    /** A check-in's duty starts at most three days before it, and the reader's look-back is six. */
    private const LOOKBACK_DAYS = 6;

    public function __construct(
        private readonly ExpectedShifts $shifts,
        private readonly AttendanceCalendar $calendar,
    ) {}

    /** @throws SchedulingException */
    public function run(Employee $employee, string $from, string $to): void
    {
        $timezone = $this->calendar->timezone();
        $id = (int) $employee->getKey();

        $checkIns = AttendanceRecord::query()->forEmployee($id)->checkIns()->accepted()
            ->inDateRange(CarbonImmutable::parse($from, $timezone)->subDays(self::LOOKBACK_DAYS)->toDateString(), CarbonImmutable::parse($to, $timezone)->addDay()->toDateString())
            ->orderBy('recorded_at')->orderBy('id')
            ->get();

        if ($checkIns->isEmpty()) {
            return;
        }

        $claimed = [];
        $matches = [];
        foreach ($checkIns as $checkIn) {
            $at = CarbonImmutable::instance($checkIn->recorded_at)->setTimezone($timezone);
            $holding = $this->shifts->dutiesHolding($employee, $at);
            $duty = null;
            foreach ($holding as $candidate) {
                if (! isset($claimed[$candidate->shiftDate()])) {
                    $duty = $candidate;

                    break;
                }
            }

            $date = $duty?->shiftDate() ?? $at->toDateString();
            if (($holding !== [] && $duty === null) || isset($claimed[$date])) {
                throw new SchedulingException("يتعارض هذا التعديل مع تسجيل حضور في {$at->toDateString()}.");
            }

            $claimed[$date] = true;
            $matches[] = [$checkIn, $duty, $date];
        }

        $this->assertNoOtherCheckInOn($id, array_keys($claimed), $checkIns->modelKeys());

        $checkOuts = [];
        foreach ($matches as [$checkIn, $duty, $date]) {
            $checkOut = AttendanceRecord::query()->forEmployee($id)->checkOuts()->accepted()
                ->whereDate('shift_date', $checkIn->shift_date->toDateString())->first();
            $close = $duty?->closesAt ?? CarbonImmutable::parse($date, $timezone)->endOfDay()->startOfSecond();

            if ($checkOut !== null && CarbonImmutable::instance($checkOut->recorded_at)->gt($close)) {
                $outDate = CarbonImmutable::instance($checkOut->recorded_at)->setTimezone($timezone)->toDateString();

                throw new SchedulingException("يقع تسجيل انصراف {$outDate} بعد إغلاق الوردية الجديدة.");
            }

            $checkOuts[] = [$checkIn, $checkOut, $duty, $date, $close];
        }

        // Applied only once every match is known, so no record is re-dated
        // onto a date another one is about to leave.
        foreach ($checkOuts as [$checkIn, $checkOut, $duty, $date, $close]) {
            $this->apply($checkIn, $checkOut, $duty, $date, $close);
        }
    }

    /**
     * A shift date carries at most one accepted check-in: one outside the
     * re-matched set already on a date taken here is a conflict.
     *
     * @param  list<string>  $dates
     * @param  list<int>  $touched
     *
     * @throws SchedulingException
     */
    private function assertNoOtherCheckInOn(int $employeeId, array $dates, array $touched): void
    {
        $other = AttendanceRecord::query()->forEmployee($employeeId)->checkIns()->accepted()
            ->whereNotIn('id', $touched)
            ->where(function ($q) use ($dates): void {
                foreach ($dates as $date) {
                    $q->orWhereDate('shift_date', $date);
                }
            })
            ->first();

        if ($other !== null) {
            throw new SchedulingException("يتعارض هذا التعديل مع تسجيل حضور في {$other->shift_date->toDateString()}.");
        }
    }

    private function apply(AttendanceRecord $checkIn, ?AttendanceRecord $checkOut, ?Duty $duty, string $date, CarbonImmutable $close): void
    {
        $now = CarbonImmutable::now($this->calendar->timezone());
        $attributes = ['shift_date' => $date, 'outside_schedule' => $duty === null];

        if ($checkOut === null) {
            if ($checkIn->status === AttendanceRecordStatus::MissingCheckout && $close->gt($now)) {
                $attributes['status'] = null;
            }
            if ($duty !== null && $duty->endsAt()->gt($now)) {
                $attributes['checkout_reminded_at'] = null;
            }
        }

        $checkIn->fill($attributes);
        if ($checkIn->isDirty()) {
            $checkIn->save();
        }

        if ($checkOut !== null && $checkOut->shift_date->toDateString() !== $date) {
            $checkOut->update(['shift_date' => $date]);
        }
    }
}
