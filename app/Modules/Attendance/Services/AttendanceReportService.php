<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Contracts\ApprovedLeave;
use App\Modules\Attendance\Contracts\LeaveSpan;
use App\Modules\Attendance\Enums\AttendanceDayState;
use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Attendance\Enums\AttendanceRecordStatus;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Modules\Attendance\Support\RowSort;
use App\Modules\Auth\Models\User;
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
    ) {}

    // ── The daily board ──────────────────────────────────────────────────

    /**
     * One day in seven disjoint sections: checked in (not out yet), checked
     * out, present (both), missing check-out, on leave, excused, and rejected —
     * a refused day the employee did not put right and no leave explains.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function daily(User $user, string $date, array $filters, ?string $sort): array
    {
        $ids = $this->population->reachable($user, $filters)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $ins = $this->events($ids)->checkIns()->accepted()->onDate($date)
            ->orderBy('recorded_at')->orderBy('id')->get()->groupBy('employee_id')->map->first();
        $outs = $this->events($ids)->checkOuts()->accepted()->onDate($date)
            ->orderBy('recorded_at')->orderBy('id')->get()->groupBy('employee_id')->map->last();

        $checkedIn = $checkedOut = $missing = [];
        foreach ($ins as $employeeId => $in) {
            $out = $outs->get($employeeId);

            if ($out !== null) {
                $checkedOut[] = $this->row($in, $out, AttendanceDayState::CheckedOut);
            } elseif ($in->status === AttendanceRecordStatus::MissingCheckout) {
                $missing[] = $this->row($in, null, AttendanceDayState::MissingCheckout);
            } else {
                $checkedIn[] = $this->row($in, null, AttendanceDayState::CheckedIn);
            }
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

        // Only refusals the employee has NOT put right, on a working day, with
        // no leave over them: someone who re-recorded the day is present, not
        // in two sections at once.
        $working = $this->calendar->isWorkingDay(Carbon::parse($date));
        $covered = [...$ins->keys()->all(), ...array_map(static fn (LeaveSpan $s): int => $s->employeeId, $spans)];
        $rejected = $working ? $this->rejectedRows($date, array_values(array_diff($ids, $covered))) : [];

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
            'working_day' => $working,
            ...$sections,
            'summary' => array_map('count', $sections),
        ];
    }

    /**
     * Refused check-in days, one row per day, each with the check-out the same
     * decision refused. A check-out refused on its own is not listed: its
     * check-in still stands, so the person is present.
     *
     * @param  array<int, int>  $employeeIds  those not already explained by an accepted check-in or leave
     * @return array<int, array<string, mixed>>
     */
    private function rejectedRows(string $date, array $employeeIds): array
    {
        $ins = $this->events($employeeIds)->checkIns()->rejected()->onDate($date)
            ->orderBy('recorded_at')->orderBy('id')->get()->groupBy('employee_id')->map->first();

        $outs = $this->events($employeeIds)->checkOuts()->rejected()->onDate($date)
            ->whereNotNull('rejection_batch')->get()->keyBy('rejection_batch');

        return $ins->map(fn (AttendanceRecord $in): array => $this->row(
            $in,
            $in->rejection_batch === null ? null : $outs->get($in->rejection_batch),
            AttendanceDayState::Rejected,
        ))->values()->all();
    }

    // ── The monthly report ───────────────────────────────────────────────

    /**
     * One summary row per expected employee over [$from, $to], plus totals.
     *
     * Row filters (has absences / missing check-outs) narrow the rows, and the
     * totals are recomputed over what survives, so the summary always matches
     * what is listed.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function monthly(User $user, string $from, string $to, array $filters, ?bool $hasAbsences, ?bool $hasMissingCheckouts, ?string $sort): array
    {
        $employees = $this->population->expected($user, $filters)
            ->with('department')->orderBy('first_name')->orderBy('last_name')->orderBy('id')->get();
        $ids = $employees->modelKeys();
        $expected = $this->calendar->workingDaysBetween($from, $to);

        $accepted = $this->events($ids)->accepted()->inDateRange($from, $to)->get(['employee_id', 'type', 'status', 'recorded_at', 'work_hours']);
        $checkIns = $accepted->filter(fn (AttendanceRecord $r): bool => $r->type === AttendanceEventType::CheckIn);
        $attended = $this->datesByEmployee($checkIns);
        $hours = $accepted->filter(fn (AttendanceRecord $r): bool => $r->type === AttendanceEventType::CheckOut)
            ->groupBy('employee_id')->map(fn (Collection $rows): float => (float) $rows->sum('work_hours'));
        $missing = $checkIns->filter(fn (AttendanceRecord $r): bool => $r->status === AttendanceRecordStatus::MissingCheckout)
            ->countBy('employee_id');

        $spans = $this->leave->spans($ids, $from, $to);
        $leaveDays = $this->leaveDays($spans, $from, $to, $attended);
        $rejectedDays = $this->rejectedAbsenceDays($ids, $from, $to, $attended, $spans);

        $rows = $employees->map(function (Employee $employee) use ($expected, $attended, $hours, $missing, $leaveDays, $rejectedDays): array {
            $id = (int) $employee->getKey();
            $present = count($attended[$id] ?? []);
            $leave = $leaveDays[$id] ?? self::NO_LEAVE;
            $workHours = round($hours[$id] ?? 0.0, 2);

            return [
                'employee'                   => EmployeeStub::from($employee),
                'department'                 => $this->unitOf($employee),
                'expected_days'              => $expected,
                'present_days'               => $present,
                'on_leave_days'              => $leave['on_leave'],
                'excused_days'               => $leave['excused'],
                'excused_deducted_days'      => $leave['excused_deducted'],
                'excused_not_deducted_days'  => $leave['excused_not_deducted'],
                // Clamped: present is bounded by the working days in practice,
                // but corrected or seeded rows could otherwise push it below 0.
                'absent_days'                => max(0, $expected - $present - $leave['on_leave'] - $leave['excused']),
                // Days a refusal cost them — already inside absent_days, not beside it.
                'rejected_days'              => $rejectedDays[$id] ?? 0,
                'missing_checkouts'          => (int) ($missing[$id] ?? 0),
                'total_work_hours'           => $workHours,
                'total_work_hours_formatted' => $this->formatHours($workHours),
                'attendance_rate'            => $expected > 0 ? round($present / $expected * 100, 1) : null,
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
            'working_days' => $expected,
            'rows'         => $rows,
            'totals'       => $this->totals($rows, $expected),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int|float>
     */
    private function totals(array $rows, int $expected): array
    {
        $sum = static fn (string $key): int => (int) array_sum(array_column($rows, $key));

        return [
            'employees'                 => count($rows),
            'expected_days'             => $expected * count($rows),
            'present_days'              => $sum('present_days'),
            'on_leave_days'             => $sum('on_leave_days'),
            'excused_days'              => $sum('excused_days'),
            'excused_deducted_days'     => $sum('excused_deducted_days'),
            'excused_not_deducted_days' => $sum('excused_not_deducted_days'),
            'absent_days'               => $sum('absent_days'),
            'rejected_days'             => $sum('rejected_days'),
            'missing_checkouts'         => $sum('missing_checkouts'),
            'total_work_hours'          => round((float) array_sum(array_column($rows, 'total_work_hours')), 2),
        ];
    }

    /**
     * Leave working days per employee, clipped to the window, a worked day
     * left out — so the same day is never both present and on leave.
     *
     * @param  array<int, LeaveSpan>  $spans
     * @param  array<int, array<string, true>>  $attended
     * @return array<int, array<string, int>>
     */
    private function leaveDays(array $spans, string $from, string $to, array $attended): array
    {
        $result = [];

        foreach ($spans as $span) {
            $days = $this->calendar->workingDaysBetween(max($span->startDate, $from), min($span->endDate, $to), $attended[$span->employeeId] ?? []);
            $row = $result[$span->employeeId] ?? self::NO_LEAVE;

            if ($span->isExcuse) {
                $row['excused'] += $days;
                $row[$span->deductsBalance ? 'excused_deducted' : 'excused_not_deducted'] += $days;
            } else {
                $row['on_leave'] += $days;
            }

            $result[$span->employeeId] = $row;
        }

        return $result;
    }

    /**
     * Refused check-in days that became absences: not recorded again, a
     * working day, and no leave over it — one count per employee.
     *
     * @param  array<int, int>  $ids
     * @param  array<int, array<string, true>>  $attended
     * @param  array<int, LeaveSpan>  $spans
     * @return array<int, int>
     */
    private function rejectedAbsenceDays(array $ids, string $from, string $to, array $attended, array $spans): array
    {
        $refused = $this->datesByEmployee($this->events($ids)->checkIns()->rejected()->inDateRange($from, $to)->get(['employee_id', 'recorded_at']));
        $result = [];

        foreach ($refused as $employeeId => $dates) {
            $count = 0;
            foreach (array_keys($dates) as $date) {
                $explained = isset($attended[$employeeId][$date])
                    || ! $this->calendar->isWorkingDay(Carbon::parse($date))
                    || $this->spanOn($spans, $employeeId, $date) !== null;
                $count += $explained ? 0 : 1;
            }
            $result[$employeeId] = $count;
        }

        return $result;
    }

    // ── One employee's days ──────────────────────────────────────────────

    /**
     * One state per day over [$from, $to], by legacy's precedence: an
     * accepted check-in, then a day off, then an excuse, then leave, then a
     * refusal, otherwise absent. A day off outranks leave because leave is
     * counted in working days everywhere else.
     *
     * The day filters narrow the grid only; the summary keeps describing the
     * whole range.
     *
     * @return array<string, mixed>
     */
    public function grid(User $user, int $employeeId, string $from, string $to, ?int $state, bool $workingDaysOnly, ?string $sort): array
    {
        $employee = Employee::withTrashed()->with('department')->findOrFail($employeeId);

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

        $id = (int) $employee->getKey();
        $byDate = static fn (Collection $rows): Collection => $rows->groupBy(fn (AttendanceRecord $r): string => $r->recorded_at->toDateString());
        $ins = $byDate($this->events([$id])->checkIns()->accepted()->inDateRange($from, $to)->orderBy('recorded_at')->orderBy('id')->get());
        $outs = $byDate($this->events([$id])->checkOuts()->accepted()->inDateRange($from, $to)->with('correctedBy.employee')->orderBy('recorded_at')->orderBy('id')->get());
        $refused = $byDate($this->events([$id])->checkIns()->rejected()->inDateRange($from, $to)->with('rejectedBy.employee')->orderBy('recorded_at')->orderBy('id')->get());
        $spans = $this->leave->spans([$id], $from, $to);

        $days = [];
        $count = ['present' => 0, 'on_leave' => 0, 'absent' => 0, 'off' => 0, 'missing' => 0, 'rejected' => 0, 'excused_charged' => 0, 'excused_free' => 0];
        $hours = 0.0;

        for ($cursor = Carbon::parse($from)->startOfDay(), $last = Carbon::parse($to)->startOfDay(); $cursor->lte($last); $cursor->addDay()) {
            $date = $cursor->toDateString();
            $working = $this->calendar->isWorkingDay($cursor);
            $in = $ins->get($date)?->first();
            $out = $outs->get($date)?->last();
            $refusedIn = $refused->get($date)?->first();
            $span = $this->spanOn($spans, $id, $date);
            $dayHours = $out?->work_hours;

            if ($in !== null) {
                $dayState = $out !== null ? AttendanceDayState::CheckedOut
                    : ($in->status === AttendanceRecordStatus::MissingCheckout ? AttendanceDayState::MissingCheckout : AttendanceDayState::CheckedIn);
                $count['present']++;
                $count['missing'] += $dayState === AttendanceDayState::MissingCheckout ? 1 : 0;
                $hours += $dayHours ?? 0.0;
            } elseif (! $working) {
                $dayState = AttendanceDayState::Off;
                $count['off']++;
            } elseif ($span?->isExcuse) {
                $dayState = AttendanceDayState::Excused;
                $count[$span->deductsBalance ? 'excused_charged' : 'excused_free']++;
            } elseif ($span !== null) {
                $dayState = AttendanceDayState::OnLeave;
                $count['on_leave']++;
            } elseif ($refusedIn !== null) {
                // HR threw the day out: it costs the day either way, so it is
                // absent; the state only says what happened.
                $dayState = AttendanceDayState::Rejected;
                $count['absent']++;
                $count['rejected']++;
            } else {
                $dayState = AttendanceDayState::Absent;
                $count['absent']++;
            }

            $days[] = [
                'date'                 => $date,
                'weekday'              => $cursor->dayOfWeek,   // 0 = Sunday … 6 = Saturday
                'working_day'          => $working,
                'status'               => $dayState->value,
                'status_label'         => $dayState->label(),
                // The day's check-in — what PATCH /records/{record}/checkout takes.
                'check_in_id'          => $in?->id,
                'check_in_time'        => $in?->recorded_at->toIso8601String(),
                'check_out_time'       => $out?->recorded_at->toIso8601String(),
                'work_hours'           => $dayHours,
                'work_hours_formatted' => $this->formatHours($dayHours),
                // The leave over the day, whatever its state, so a calendar can
                // shade the whole span.
                'leave_type'           => $span?->typeName,
                // Shown whenever the day had a refused check-in, even when leave
                // or a later check-in outranks it: the employee was told about it.
                'rejection'            => $this->rejection($refusedIn),
                'correction'           => $this->correction($out),
                'excuse'               => $dayState === AttendanceDayState::Excused ? $span?->details() : null,
            ];
        }

        $expected = $this->calendar->workingDaysBetween($from, $to);
        $excused = $count['excused_charged'] + $count['excused_free'];
        $hours = round($hours, 2);

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
            'working_days' => $expected,
            'days'         => RowSort::apply(array_values($days), $sort, self::gridKeys()),
            'summary'      => [
                'expected_days'              => $expected,
                'present_days'               => $count['present'],
                'on_leave_days'              => $count['on_leave'],
                'excused_days'               => $excused,
                'excused_deducted_days'      => $count['excused_charged'],
                'excused_not_deducted_days'  => $count['excused_free'],
                'absent_days'                => $count['absent'],
                'rejected_days'              => $count['rejected'],
                'off_days'                   => $count['off'],
                'missing_checkouts'          => $count['missing'],
                'total_work_hours'           => $hours,
                'total_work_hours_formatted' => $this->formatHours($hours),
                'attendance_rate'            => $expected > 0 ? round($count['present'] / $expected * 100, 1) : null,
            ],
        ];
    }

    // ── Absent today ─────────────────────────────────────────────────────

    /**
     * Expected employees with no accepted check-in on $date — and, unless the
     * config allows attendance during leave, no approved leave over it. A day
     * off has no absentees.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Employee>
     */
    public function absent(User $user, string $date, array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $query = $this->population->expected($user, $filters)->with('department');

        if (! $this->calendar->isWorkingDay(Carbon::parse($date))) {
            return $query->whereRaw('1 = 0')->paginate($perPage);
        }

        $query->whereNotIn('employees.id', AttendanceRecord::query()->select('employee_id')->checkIns()->accepted()->onDate($date));

        if (! $this->calendar->allowsAttendanceDuringLeave()) {
            $onLeave = array_map(
                static fn (LeaveSpan $span): int => $span->employeeId,
                $this->leave->spans((clone $query)->pluck('employees.id')->map(fn ($id): int => (int) $id)->all(), $date, $date),
            );
            $query->whereNotIn('employees.id', $onLeave);
        }

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
     * @return array<string, mixed>
     */
    private function row(AttendanceRecord $in, ?AttendanceRecord $out, AttendanceDayState $state): array
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
            'work_hours'           => $out?->work_hours,
            'work_hours_formatted' => $this->formatHours($out?->work_hours),
            'check_in_location'    => ['latitude' => $in->latitude, 'longitude' => $in->longitude],
            'check_out_location'   => $out === null ? null : ['latitude' => $out->latitude, 'longitude' => $out->longitude],
            // No URLs (Q11): the selfie endpoint streams them by record id.
            'check_in_has_selfie'  => $in->selfie_path !== null,
            'check_out_has_selfie' => $out === null ? null : $out->selfie_path !== null,
            'correction'           => $this->correction($out),
            'rejection'            => $this->rejection($in),
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

    /** @return array<string, mixed>|null */
    private function rejection(?AttendanceRecord $record): ?array
    {
        return $record?->rejected_at === null ? null : [
            'record_id'    => $record->id,
            'recorded_at'  => $record->recorded_at->toIso8601String(),
            'reason'       => $record->rejection_reason?->value,
            'reason_label' => $record->rejection_reason?->label(),
            'note'         => $record->rejection_note,
            'rejected_at'  => $record->rejected_at->toIso8601String(),
            'rejected_by'  => Actor::of($record->rejectedBy),
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

    /**
     * @param  Collection<int, AttendanceRecord>  $records
     * @return array<int, array<string, true>> employee id => the dates they appear on
     */
    private function datesByEmployee(Collection $records): array
    {
        $dates = [];
        foreach ($records as $record) {
            $dates[(int) $record->employee_id][$record->recorded_at->toDateString()] = true;
        }

        return $dates;
    }

    /** @param  array<int, LeaveSpan>  $spans */
    private function spanOn(array $spans, int $employeeId, string $date): ?LeaveSpan
    {
        foreach ($spans as $span) {
            if ($span->employeeId === $employeeId && $span->covers($date)) {
                return $span;
            }
        }

        return null;
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
