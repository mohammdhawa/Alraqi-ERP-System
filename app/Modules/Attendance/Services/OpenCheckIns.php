<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Contracts\ExpectedShifts;
use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Modules\Attendance\Support\Duty;
use App\Modules\HR\Models\Employee;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Which check-in is still open (work shifts spec §5.1 rule 2, §5.2).
 *
 * OPEN IS JUDGED ON MOMENTS, NOT ON THE `status` FLAG. A check-in is open for
 * a moment when nothing has checked it out and its close is not before that
 * moment. A check-in the close has already flagged is therefore still open
 * for a check-out recorded before that close — parity audit S-2: an offline
 * check-out that syncs late lands and clears the flag.
 */
class OpenCheckIns
{
    /**
     * How far back a check-in's shift date can lie and still be open: a duty
     * is at most three 24-hour shifts, and one starting late on its date ends
     * late on the fourth date after it — its close (end + 4 h) can fall on
     * the fifth. One constant with the shift clock, so the two agree.
     */
    public const LOOKBACK_DAYS = Duty::MAX_SHIFTS + 1;

    public function __construct(
        private readonly AttendanceCalendar $calendar,
        private readonly ExpectedShifts $shifts,
    ) {}

    /**
     * When the check-in stops being open: its duty's close (the end plus the
     * close margin). An outside-schedule check-in, or one whose duty the
     * schedule no longer has, closes at the end of its shift date, as every
     * check-in did before shifts.
     */
    public function closeOf(AttendanceRecord $checkIn): CarbonImmutable
    {
        return $this->dutyOf($checkIn)?->closesAt
            ?? CarbonImmutable::parse($checkIn->shift_date->toDateString(), $this->calendar->timezone())->endOfDay()->startOfSecond();
    }

    /** The duty a check-in was taken for, or null when it is outside the schedule. */
    public function dutyOf(AttendanceRecord $checkIn): ?Duty
    {
        if ($checkIn->outside_schedule) {
            return null;
        }

        $employee = $checkIn->relationLoaded('employee') ? $checkIn->employee : Employee::withTrashed()->find($checkIn->employee_id);

        return $employee === null ? null : $this->shifts->dutyOn($employee, $checkIn->shift_date->toDateString());
    }

    /** The check-in a check-out recorded at $moment closes, or null. */
    public function forCheckOut(Employee $employee, CarbonInterface $moment): ?AttendanceRecord
    {
        return $this->openAt($employee, $moment);
    }

    /** An open check-in that stops a new check-in at $moment, or null. */
    public function blocking(Employee $employee, CarbonInterface $moment): ?AttendanceRecord
    {
        return $this->openAt($employee, $moment);
    }

    private function openAt(Employee $employee, CarbonInterface $moment): ?AttendanceRecord
    {
        $date = CarbonImmutable::instance($moment)->setTimezone($this->calendar->timezone());

        return AttendanceRecord::forEmployee((int) $employee->getKey())
            ->checkIns()->accepted()
            ->inDateRange($date->subDays(self::LOOKBACK_DAYS)->toDateString(), $date->toDateString())
            ->whereNotExists(fn ($out) => $out->selectRaw('1')
                ->from('attendance_records as outs')
                ->whereColumn('outs.employee_id', 'attendance_records.employee_id')
                ->whereColumn('outs.shift_date', 'attendance_records.shift_date')
                ->where('outs.type', AttendanceEventType::CheckOut->value)
                ->whereNull('outs.rejected_at')
                ->whereNull('outs.deleted_at'))
            ->orderByDesc('recorded_at')->orderByDesc('id')
            ->get()
            ->first(fn (AttendanceRecord $checkIn): bool => $this->closeOf($checkIn)->gte($moment));
    }
}
