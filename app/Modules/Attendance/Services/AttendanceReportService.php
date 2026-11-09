<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Contracts\ApprovedLeave;
use App\Modules\Attendance\Contracts\ExpectedShifts;
use App\Modules\Attendance\Contracts\LeaveSpan;
use App\Modules\Attendance\Enums\AttendanceDayState;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Modules\Attendance\Support\DayFacts;
use App\Modules\Attendance\Support\RowSort;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Resources\EmployeeStub;
use App\Shared\Support\Actor;
use App\Shared\Support\OutOfScope;
use App\Shared\Support\RequestRowScopes;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The attendance reports — legacy's AttendanceService::report(),
 * monthlyReport(), employeeDailyBreakdown() and absentUsersQuery(), with
 * their rules unchanged (work order §4.5 slice 2b; docs/ATTENDANCE_BUILD.md):
 *
 * - a refused event counts nowhere: it is listed apart, and its day is absent
 *   unless the employee recorded it again or leave explains it;
 * - leave is counted in working days, the same calendar as the balance, and a
 *   day the employee actually worked is never also a leave day;
 * - the arithmetic closes: expected = present + on leave + excused + absent.
 *
 * WHO: the daily board and the grid read every employee the caller reaches;
 * the monthly report and absent-today read those EXPECTED to attend — active
 * employees with an active account (Q7). Both through AttendancePopulation,
 * with the same person filters.
 *
 * LEAVE comes through attendance's own ApprovedLeave contract, so these work
 * before the Leave module exists (nobody is on leave) and unchanged after.
 *
 * The reports are computed objects, not lists: they are not paginated — a
 * monthly report's totals must be over every row — and their rows are
 * sorted in memory (RowSort). absent-today is a list, and is paginated.
 */
class AttendanceReportService
{
    /** The daily board's sort keys → how to read them from a row. */
    public const DAILY_SORTS = ['employee_name', 'department', 'status', 'check_in_time', 'check_out_time', 'work_hours'];

    public const MONTHLY_SORTS = [
        'employee_name', 'department', 'expected_days', 'present_days', 'on_leave_days', 'absent_days',
        'excused_days', 'excused_deducted_days', 'excused_not_deducted_days', 'rejected_days',
        'missing_checkouts', 'total_work_hours', 'attendance_rate',
    ];

    public const GRID_SORTS = ['date', 'status', 'work_hours'];

    /** absent-today's sorts → employees column. */
    public const ABSENT_SORTS = ['employee_name' => 'first_name', 'employee_number' => 'employee_number'];

    private const NO_LEAVE = ['on_leave' => 0, 'excused' => 0, 'excused_deducted' => 0, 'excused_not_deducted' => 0];

    public function __construct(
        private readonly AttendanceCalendar $calendar,
        private readonly ApprovedLeave $leave,
        private readonly AttendancePopulation $population,
        private readonly AttendanceDays $dayFacts,
        private readonly ExpectedShifts $shifts,
    ) {}

    // ── The daily board ──────────────────────────────────────────────────

    /**
     * One day in seven disjoint sections: checked in (not out yet), checked
     * out, present (both), missing check-out, on leave, excused, and rejected —
     * a refused day the employee did not put right and no leave explains.
     *
     * The date is a SHIFT date (work shifts spec §6.3): a guard on day two of
     * a duty is checked in on it, by the duty's one check-in.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function daily(User $user, string $date, array $filters, ?string $sort): array
    {
        // Departed employees are not counted (parity audit C-8).
        $employees = $this->population->onRoll($user, $filters)->with('department')->get();
        $ids = $employees->modelKeys();
        $facts = $this->dayFacts->for($employees, $date, $date);

        $checkedIn = $checkedOut = $missing = $refusedIds = [];
        foreach ($facts as $employeeId => $byDate) {
            $day = $byDate[$date];
            match ($day->state) {
                AttendanceDayState::CheckedOut      => $checkedOut[] = $this->row($day->in, $day->out, $day->state, 0, $day),
                AttendanceDayState::MissingCheckout => $missing[] = $this->row($day->in, null, $day->state, 0, $day),
                AttendanceDayState::CheckedIn       => $checkedIn[] = $this->row($day->in, null, $day->state, 0, $day),
                AttendanceDayState::Rejected        => $refusedIds[] = (int) $employeeId,
                default                             => null,
            };
        }

        // Planned leave and HR excuses are both approved leave, but they answer
        // different questions on a daily board — who is scheduled off, and
        // whose absence was justified — so they list apart.
        $spans = $this->leave->spans($ids, $date, $date);
        $people = $this->peopleOf(array_map(static fn (LeaveSpan $s): int => $s->employeeId, $spans));
        $onLeave = $excused = [];
        foreach ($spans as $span) {
            $row = $this->leaveRow($span, $people->get($span->employeeId));
            if ($span->isExcuse) {
                $excused[] = $row;
            } else {
                $onLeave[] = $row;
            }
        }

        // Only refusals the employee has NOT put right, on one of their working
        // days, with no leave over them: someone who re-recorded the day is
        // present, not in two sections at once.
        $rejected = $refusedIds === [] ? [] : $this->rejectedRows($date, $refusedIds);

        $byCheckIn = static fn (array $rows): array => RowSort::apply($rows, 'check_in_time', self::rowKeys());
        $present = $byCheckIn([...$checkedIn, ...$checkedOut]);
        $sections = [
            'checked_in'       => $byCheckIn($checkedIn),
            'checked_out'      => RowSort::apply($checkedOut, 'check_out_time', self::rowKeys()),
            'present'          => $present,
            'on_leave'         => $onLeave,
            'excused'          => $excused,
            'missing_checkout' => $byCheckIn($missing),
            'rejected'         => $byCheckIn($rejected),
        ];
        $sections = array_map(static fn (array $rows): array => RowSort::apply($rows, $sort, self::rowKeys()), $sections);

        return [
            'date'        => $date,
            // The DEFAULT schedule's: each person's own is on their row.
            'working_day' => $this->defaultWorkingDays($date, $date) > 0,
            ...$sections,
            'summary' => array_map('count', $sections),
        ];
    }

    /**
     * Refused check-in days, one row per day, each with the check-out the same
     * decision refused. A check-out refused on its own is not listed: its
     * check-in still stands, so the person is present.
     *
     * The row is the day's first refusal; `rejection.refused_check_ins` says
     * how many there were, since every attempt after it is only in the
     * records list.
     *
     * @param  array<int, int>  $employeeIds  those whose day is a refusal
     * @return array<int, array<string, mixed>>
     */
    private function rejectedRows(string $date, array $employeeIds): array
    {
        $days = $this->events($employeeIds)->checkIns()->rejected()->onDate($date)
            ->orderBy('recorded_at')->orderBy('id')->get()->groupBy('employee_id');

        $outs = $this->events($employeeIds)->checkOuts()->rejected()->onDate($date)
            ->whereNotNull('rejection_batch')->get()->keyBy('rejection_batch');

        return $days->map(function (Collection $day) use ($outs): array {
            $in = $day->first();

            return $this->row(
                $in,
                $in->rejection_batch === null ? null : $outs->get($in->rejection_batch),
                AttendanceDayState::Rejected,
                $day->count(),
            );
        })->values()->all();
    }

    // ── The monthly report ───────────────────────────────────────────────

    /**
     * One summary row per expected employee over [$from, $to], plus totals —
     * the same day facts as the grid, summed.
     *
     * Row filters (has absences / missing check-outs) narrow the rows, and the
     * totals are recomputed over what survives, so the summary always matches
     * what is listed.
     *
     * THE DAYS SO FAR (owner, 2026-10-20, parity audit S-1): only shifts that
     * have ended, or that something already covers, are expected; a shift
     * still to come is neither expected nor absent. `working_days` names the
     * default schedule's working days in the window, `counted_to` the last
     * date counted (null: none yet).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function monthly(User $user, string $from, string $to, array $filters, ?bool $hasAbsences, ?bool $hasMissingCheckouts, ?string $sort): array
    {
        $employees = $this->population->expected($user, $filters)
            ->with('department')->orderBy('first_name')->orderBy('last_name')->orderBy('id')->get();
        $facts = $this->dayFacts->for($employees, $from, $to);

        $rows = $employees->map(function (Employee $employee) use ($facts): array {
            $c = $this->tally($facts[(int) $employee->getKey()] ?? []);

            return [
                'employee'                   => EmployeeStub::from($employee),
                'department'                 => $this->unitOf($employee),
                'expected_days'              => $c['expected'],
                'present_days'               => $c['present'],
                'on_leave_days'              => $c['on_leave'],
                'excused_days'               => $c['excused_charged'] + $c['excused_free'],
                'excused_deducted_days'      => $c['excused_charged'],
                'excused_not_deducted_days'  => $c['excused_free'],
                // Clamped: a check-in on a day off counts as present but not
                // as expected, so present can exceed what is left.
                'absent_days'                => max(0, $c['expected'] - $c['present'] - $c['on_leave'] - $c['excused_charged'] - $c['excused_free']),
                // Days a refusal cost them — already inside absent_days, not beside it.
                'rejected_days'              => $c['rejected'],
                'missing_checkouts'          => $c['missing'],
                'late_count'                 => $c['late_count'],
                'late_minutes'               => $c['late_minutes'],
                'early_departure_count'      => $c['early_count'],
                'early_departure_minutes'    => $c['early_minutes'],
                'outside_schedule_count'     => $c['outside'],
                'total_work_hours'           => $c['hours'],
                'total_work_hours_formatted' => $this->formatHours($c['hours']),
                'attendance_rate'            => $c['expected'] > 0 ? round($c['present'] / $c['expected'] * 100, 1) : null,
            ];
        })->all();

        if ($hasAbsences !== null) {
            $rows = array_filter($rows, static fn (array $row): bool => ($row['absent_days'] > 0) === $hasAbsences);
        }
        if ($hasMissingCheckouts !== null) {
            $rows = array_filter($rows, static fn (array $row): bool => ($row['missing_checkouts'] > 0) === $hasMissingCheckouts);
        }
        $rows = RowSort::apply(array_values($rows), $sort, self::monthlyKeys());

        return [
            'from'         => $from,
            'to'           => $to,
            'working_days' => $this->defaultWorkingDays($from, $to),
            'counted_to'   => $this->countTo($from, $to),
            'rows'         => $rows,
            'totals'       => $this->totals($rows),
        ];
    }

    /**
     * The last date a report counts: today, or the window's end if earlier;
     * null when the window starts after today (parity audit S-1).
     */
    private function countTo(string $from, string $to): ?string
    {
        $countTo = min($to, $this->calendar->today());

        return $countTo < $from ? null : $countTo;
    }

    /**
     * The counted dates of one person, summed — the grid's summary and a
     * monthly row are the same arithmetic.
     *
     * @param  array<string, DayFacts>  $days
     * @return array<string, int|float>
     */
    private function tally(array $days): array
    {
        $c = ['expected' => 0, 'present' => 0, 'on_leave' => 0, 'absent' => 0, 'off' => 0, 'missing' => 0, 'rejected' => 0,
            'excused_charged' => 0, 'excused_free' => 0, 'late_count' => 0, 'late_minutes' => 0, 'early_count' => 0,
            'early_minutes' => 0, 'outside' => 0, 'hours' => 0.0];

        foreach ($days as $day) {
            if (! $day->counted) {
                continue;
            }
            $c['expected'] += $day->working() ? 1 : 0;
            $c['present'] += in_array($day->state, [AttendanceDayState::CheckedIn, AttendanceDayState::CheckedOut, AttendanceDayState::MissingCheckout], true) ? 1 : 0;
            $c['missing'] += $day->state === AttendanceDayState::MissingCheckout ? 1 : 0;
            $c['off'] += $day->state === AttendanceDayState::Off ? 1 : 0;
            $c['on_leave'] += $day->state === AttendanceDayState::OnLeave ? 1 : 0;
            $c['rejected'] += $day->state === AttendanceDayState::Rejected ? 1 : 0;
            $c['absent'] += in_array($day->state, [AttendanceDayState::Absent, AttendanceDayState::Rejected], true) ? 1 : 0;
            if ($day->state === AttendanceDayState::Excused) {
                $c[$day->span?->deductsBalance ? 'excused_charged' : 'excused_free']++;
            }
            $c['late_count'] += $day->lateMinutes() > 0 ? 1 : 0;
            $c['late_minutes'] += $day->lateMinutes();
            $c['early_count'] += $day->earlyDepartureMinutes() > 0 ? 1 : 0;
            $c['early_minutes'] += $day->earlyDepartureMinutes();
            $c['outside'] += $day->outsideSchedule() ? 1 : 0;
            $c['hours'] += $day->hours ?? 0.0;
        }
        $c['hours'] = round($c['hours'], 2);

        return $c;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int|float>
     */
    private function totals(array $rows): array
    {
        $sum = static fn (string $key): int => (int) array_sum(array_column($rows, $key));

        return [
            'employees'                 => count($rows),
            'expected_days'             => $sum('expected_days'),
            'present_days'              => $sum('present_days'),
            'on_leave_days'             => $sum('on_leave_days'),
            'excused_days'              => $sum('excused_days'),
            'excused_deducted_days'     => $sum('excused_deducted_days'),
            'excused_not_deducted_days' => $sum('excused_not_deducted_days'),
            'absent_days'               => $sum('absent_days'),
            'rejected_days'             => $sum('rejected_days'),
            'missing_checkouts'         => $sum('missing_checkouts'),
            'late_count'                => $sum('late_count'),
            'late_minutes'              => $sum('late_minutes'),
            'early_departure_count'     => $sum('early_departure_count'),
            'early_departure_minutes'   => $sum('early_departure_minutes'),
            'outside_schedule_count'    => $sum('outside_schedule_count'),
            'total_work_hours'          => round((float) array_sum(array_column($rows, 'total_work_hours')), 2),
        ];
    }

    /** The default schedule's working days in the window — the page-level fact. */
    private function defaultWorkingDays(string $from, string $to): int
    {
        // Employee id 0 has no schedule of its own, so it reads the default.
        return count($this->shifts->shiftsFor([0], $from, $to)[0] ?? []);
    }

    // ── One employee's days ──────────────────────────────────────────────

    /**
     * One state per day over [$from, $to], by legacy's precedence: a record,
     * then a day off, then an excuse, then leave, then a refusal, otherwise
     * absent. Each person's days come from their own schedule (work shifts
     * spec §6): a day off is a date without a shift.
     *
     * The days so far (parity audit S-1): a shift not yet ended, with nothing
     * covering it, is NotYet, and nothing after today is counted; the summary
     * covers [$from, `counted_to`].
     *
     * The day filters narrow the grid only; the summary keeps describing the
     * whole range.
     *
     * @return array<string, mixed>
     */
    public function grid(User $user, int $employeeId, string $from, string $to, ?int $state, bool $workingDaysOnly, ?string $sort): array
    {
        // A departed employee — soft-deleted or terminated — is not reached
        // at all: 404, as legacy's grid answered for a deleted user (owner,
        // 2026-10-20, parity audit C-8).
        $employee = Employee::query()->with('department')
            ->where('status', '!=', EmployeeStatus::Terminated)
            ->findOrFail($employeeId);

        // The employee's CURRENT unit must be in reach (D6) — the same rule as
        // the records list. 403, not 404: decision 19.
        $unit = $employee->department_id === null ? null : (int) $employee->department_id;
        if (! RequestRowScopes::forUser($user, 'attendance.records.view')->covers($unit)) {
            OutOfScope::deny('هذا الموظف خارج نطاق صلاحياتك.')->authorize();
        }

        return $this->days($employee, $from, $to, $state, $workingDaysOnly, $sort);
    }

    /**
     * The grid's days and summary, WITHOUT the scope check — for a caller
     * that has already settled who may read them: `grid()` above, and the
     * employee's own home screen (`/api/me/summary`, work order §4.8), which
     * reads only the caller's own employee.
     *
     * @return array<string, mixed>
     */
    public function days(Employee $employee, string $from, string $to, ?int $state = null, bool $workingDaysOnly = false, ?string $sort = null): array
    {
        $employee->loadMissing('department');
        $facts = $this->dayFacts->for(collect([$employee]), $from, $to)[(int) $employee->getKey()] ?? [];
        $c = $this->tally($facts);

        $days = [];
        foreach ($facts as $date => $day) {
            $cursor = Carbon::parse($date);
            $days[] = [
                'date'                    => $date,
                'weekday'                 => $cursor->dayOfWeek,   // 0 = Sunday … 6 = Saturday
                // This person has a shift on the date (work shifts spec §6.4).
                'working_day'             => $day->working(),
                'shift'                   => $day->shift?->toArray(),
                'status'                  => $day->state->value,
                'status_label'            => $day->state->label(),
                // The duty's check-in — what PATCH /records/{record}/checkout takes.
                'check_in_id'             => $day->in?->id,
                'check_in_time'           => $day->in?->recorded_at->toIso8601String(),
                'check_out_time'          => $day->out?->recorded_at->toIso8601String(),
                'work_hours'              => $day->hours,
                'work_hours_formatted'    => $this->formatHours($day->hours),
                'late_minutes'            => $day->lateMinutes(),
                'early_departure_minutes' => $day->earlyDepartureMinutes(),
                'minutes_within_shift'    => $day->minutesWithin(),
                'minutes_outside_shift'   => $day->minutesOutside(),
                'outside_schedule'        => $day->outsideSchedule(),
                // The leave over the day, whatever its state, so a calendar can
                // shade the whole span.
                'leave_type'              => $day->span?->typeName,
                // Shown whenever the day had a refused check-in, even when leave
                // or a later check-in outranks it: the employee was told about it.
                'rejection'               => $this->rejection($day->refusedIn, $day->refusedCount),
                'correction'              => $this->correction($day->out),
                'excuse'                  => $day->state === AttendanceDayState::Excused ? $day->span?->details() : null,
            ];
        }

        if ($state !== null) {
            $days = array_filter($days, static fn (array $day): bool => $day['status'] === $state);
        }
        if ($workingDaysOnly) {
            $days = array_filter($days, static fn (array $day): bool => $day['working_day']);
        }

        return [
            'employee'     => EmployeeStub::from($employee),
            'department'   => $this->unitOf($employee),
            'from'         => $from,
            'to'           => $to,
            'working_days' => count(array_filter($facts, static fn (DayFacts $d): bool => $d->working())),
            'counted_to'   => $this->countTo($from, $to),
            'days'         => RowSort::apply(array_values($days), $sort, self::gridKeys()),
            'summary'      => [
                'expected_days'              => $c['expected'],
                'present_days'               => $c['present'],
                'on_leave_days'              => $c['on_leave'],
                'excused_days'               => $c['excused_charged'] + $c['excused_free'],
                'excused_deducted_days'      => $c['excused_charged'],
                'excused_not_deducted_days'  => $c['excused_free'],
                'absent_days'                => $c['absent'],
                'rejected_days'              => $c['rejected'],
                'off_days'                   => $c['off'],
                'missing_checkouts'          => $c['missing'],
                'total_work_hours'           => $c['hours'],
                'total_work_hours_formatted' => $this->formatHours($c['hours']),
                'attendance_rate'            => $c['expected'] > 0 ? round($c['present'] / $c['expected'] * 100, 1) : null,
            ],
        ];
    }

    // ── Absent today ─────────────────────────────────────────────────────

    /**
     * Expected employees whose shift on $date has ended with no accepted
     * check-in — and, unless the config allows attendance during leave, no
     * approved leave over it (work shifts spec §6.3). A day off has no
     * absentees.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Employee>
     */
    public function absent(User $user, string $date, array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $query = $this->population->expected($user, $filters)->with('department');
        $now = Carbon::now($this->calendar->timezone());
        $allowLeave = $this->calendar->allowsAttendanceDuringLeave();

        $absent = [];
        foreach ($this->dayFacts->for((clone $query)->get(), $date, $date) as $employeeId => $byDate) {
            $day = $byDate[$date];
            $due = $day->shift !== null && $day->shift->endsAt->lte($now);
            $onLeave = in_array($day->state, [AttendanceDayState::OnLeave, AttendanceDayState::Excused], true);
            if ($due && $day->in === null && ($allowLeave || ! $onLeave)) {
                $absent[] = (int) $employeeId;
            }
        }
        $query->whereIn('employees.id', $absent);

        $direction = $sort !== null && str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = $sort === null ? null : self::ABSENT_SORTS[ltrim($sort, '-')];

        return ($column === null ? $query->orderBy('first_name')->orderBy('last_name') : $query->orderBy($column, $direction))
            ->orderBy('employees.id', $direction)
            ->paginate($perPage);
    }

    // ── Shared ───────────────────────────────────────────────────────────

    /**
     * @param  array<int, int>  $employeeIds
     * @return Builder<AttendanceRecord>
     */
    private function events(array $employeeIds): Builder
    {
        return AttendanceRecord::query()
            ->with(['employee.department', 'correctedBy.employee', 'rejectedBy.employee'])
            ->whereIn('employee_id', $employeeIds);
    }

    /**
     * One board row, keyed on the day's check-in.
     *
     * @param  int  $refusedCheckIns  the day's refused check-ins; only a rejected row has any
     * @return array<string, mixed>
     */
    private function row(AttendanceRecord $in, ?AttendanceRecord $out, AttendanceDayState $state, int $refusedCheckIns = 0, ?DayFacts $day = null): array
    {
        return [
            // The check-in — what the correction and refusal endpoints take.
            'record_id'            => $in->id,
            'check_out_record_id'  => $out?->id,
            'employee'             => EmployeeStub::from($in->employee),
            'department'           => $this->unitOf($in->employee),
            'status'               => $state->value,
            'status_label'         => $state->label(),
            'check_in_time'        => $in->recorded_at->toIso8601String(),
            'check_out_time'       => $out?->recorded_at->toIso8601String(),
            'work_hours'           => $day === null ? $out?->work_hours : $day->hours,
            'work_hours_formatted' => $this->formatHours($day === null ? $out?->work_hours : $day->hours),
            // The person's shift on the board's date, and how they kept it
            // (work shifts spec §6.3); none on a refused row.
            'shift'                   => $day?->shift?->toArray(),
            'late_minutes'            => $day?->lateMinutes() ?? 0,
            'early_departure_minutes' => $day?->earlyDepartureMinutes() ?? 0,
            'outside_schedule'        => (bool) $in->outside_schedule,
            'check_in_location'    => ['latitude' => $in->latitude, 'longitude' => $in->longitude],
            'check_out_location'   => $out === null ? null : ['latitude' => $out->latitude, 'longitude' => $out->longitude],
            // No URLs (Q11): the selfie endpoint streams them by record id.
            'check_in_has_selfie'  => $in->selfie_path !== null,
            'check_out_has_selfie' => $out === null ? null : $out->selfie_path !== null,
            'correction'           => $this->correction($out),
            'rejection'            => $this->rejection($in, $refusedCheckIns),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function leaveRow(LeaveSpan $span, ?Employee $employee): array
    {
        $state = $span->isExcuse ? AttendanceDayState::Excused : AttendanceDayState::OnLeave;

        return [
            'leave_request_id' => $span->leaveRequestId,
            'employee'         => EmployeeStub::from($employee),
            'department'       => $employee === null ? null : $this->unitOf($employee),
            'status'           => $state->value,
            'status_label'     => $state->label(),
            'leave_type'       => $span->typeCode,
            'leave_type_name'  => $span->typeName,
            'is_excuse'        => $span->isExcuse,
            'deducts_balance'  => $span->deductsBalance,
            'excuse'           => $span->isExcuse ? $span->details() : null,
            // Null on an excuse, which nobody approved; excuse.recorded_by is its equivalent.
            'approved_by'      => $span->isExcuse ? null : $span->approvedBy,
            'start_date'       => $span->startDate,
            'end_date'         => $span->endDate,
            'check_in_time'    => null,
            'check_out_time'   => null,
            'work_hours'       => null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function correction(?AttendanceRecord $out): ?array
    {
        return $out?->corrected_at === null ? null : [
            'by'   => Actor::of($out->correctedBy),
            'at'   => $out->corrected_at->toIso8601String(),
            'note' => $out->correction_note,
        ];
    }

    /**
     * The refusal shown for a day: [$record] is its first refused check-in,
     * and [$refusedCheckIns] how many the day had, that one included.
     *
     * @return array<string, mixed>|null
     */
    private function rejection(?AttendanceRecord $record, int $refusedCheckIns): ?array
    {
        return $record?->rejected_at === null ? null : [
            'record_id'         => $record->id,
            'recorded_at'       => $record->recorded_at->toIso8601String(),
            'reason'            => $record->rejection_reason?->value,
            'reason_label'      => $record->rejection_reason?->label(),
            'note'              => $record->rejection_note,
            'rejected_at'       => $record->rejected_at->toIso8601String(),
            'rejected_by'       => Actor::of($record->rejectedBy),
            'refused_check_ins' => $refusedCheckIns,
        ];
    }

    /** @return array{id: int, name: string}|null */
    private function unitOf(?Employee $employee): ?array
    {
        $unit = $employee?->department;

        return $unit === null ? null : ['id' => (int) $unit->getKey(), 'name' => $unit->name];
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, Employee>
     */
    private function peopleOf(array $ids): Collection
    {
        return Employee::withTrashed()->with('department')->whereKey(array_unique($ids))->get()->keyBy('id');
    }

    /** Legacy's "8h 30m", or null. */
    private function formatHours(?float $hours): ?string
    {
        if ($hours === null) {
            return null;
        }

        $whole = (int) floor($hours);

        return $whole.'h '.(int) round(($hours - $whole) * 60).'m';
    }

    /** @return array<string, callable(array<string, mixed>): mixed> */
    private static function rowKeys(): array
    {
        return [
            'employee_name'  => static fn (array $row): string => (string) ($row['employee']['full_name'] ?? ''),
            'department'     => static fn (array $row): string => (string) ($row['department']['name'] ?? ''),
            'status'         => static fn (array $row): int => $row['status'],
            'check_in_time'  => static fn (array $row): string => (string) $row['check_in_time'],
            'check_out_time' => static fn (array $row): string => (string) $row['check_out_time'],
            'work_hours'     => static fn (array $row): float => (float) $row['work_hours'],
        ];
    }

    /** @return array<string, callable(array<string, mixed>): mixed> */
    private static function monthlyKeys(): array
    {
        $keys = [
            'employee_name' => static fn (array $row): string => (string) ($row['employee']['full_name'] ?? ''),
            'department'    => static fn (array $row): string => (string) ($row['department']['name'] ?? ''),
        ];
        foreach (array_slice(self::MONTHLY_SORTS, 2) as $key) {
            $keys[$key] = static fn (array $row): float => (float) $row[$key];
        }

        return $keys;
    }

    /** @return array<string, callable(array<string, mixed>): mixed> */
    private static function gridKeys(): array
    {
        return [
            'date'       => static fn (array $day): string => $day['date'],
            'status'     => static fn (array $day): int => $day['status'],
            'work_hours' => static fn (array $day): float => (float) $day['work_hours'],
        ];
    }
}
