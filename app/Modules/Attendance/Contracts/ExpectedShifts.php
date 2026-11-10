<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Contracts;

use App\Modules\Attendance\Support\Duty;
use App\Modules\Attendance\Support\ExpectedShift;
use App\Modules\HR\Models\Employee;
use Carbon\CarbonInterface;

/**
 * Who is expected when (work shifts spec §4.1). Implemented by the
 * Scheduling module, bound in AppServiceProvider — the same arrangement as
 * ApprovedLeave, which Leave implements for Attendance. Attendance and Leave
 * read schedules only through this, so neither touches the schedule tables.
 */
interface ExpectedShifts
{
    /**
     * Each person's expected shift per date; days off are absent.
     *
     * @param  array<int, int>  $employeeIds
     * @return array<int, array<string, ExpectedShift>> employee id → date → shift
     */
    public function shiftsFor(array $employeeIds, string $from, string $to): array;

    /**
     * The duties whose check-in window holds $moment, earliest start first.
     * Attendance picks the first without a check-in (spec §5.1), so
     * Scheduling never reads attendance records.
     *
     * @return list<Duty>
     */
    public function dutiesHolding(Employee $employee, CarbonInterface $moment): array;

    /** The duty that starts on $shiftDate, or null — for a record already dated. */
    public function dutyOn(Employee $employee, string $shiftDate): ?Duty;

    /**
     * Dates in the inclusive span on which the employee has a shift — the
     * days a leave charges. THE ONE HOOK FOR A HOLIDAY CALENDAR, as
     * AttendanceCalendar::isChargeableDay() was before it (spec §7).
     */
    public function workingDaysBetween(Employee $employee, string $from, string $to): int;
}
