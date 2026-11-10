<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Contracts\ApprovedLeave;
use App\Modules\Attendance\Contracts\ExpectedShifts;
use App\Modules\Attendance\Contracts\LeaveSpan;
use App\Modules\Attendance\Enums\AttendanceDayState;
use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Attendance\Enums\AttendanceRecordStatus;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Modules\Attendance\Support\DayFacts;
use App\Modules\Attendance\Support\Duty;
use App\Modules\Attendance\Support\DutyPresence;
use App\Modules\Attendance\Support\ExpectedShift;
use App\Modules\Attendance\Support\ShiftPresence;
use App\Modules\HR\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Every person's dates, decided once for every report (work shifts spec §6):
 * the daily board, the grid, the monthly report, absent-today and
 * /api/me/summary all read these facts, so they cannot disagree.
 *
 * A DUTY'S RECORDS ARE SPREAD OVER ITS SHIFTS. A check-in carries its duty's
 * start date; its presence is split over the duty's shifts (DutyPresence),
 * and each date whose shift holds some of it shows the duty's check-in. A
 * date's shift with none of it is absent once the shift has ended — a guard
 * who first arrives on day two. A missing check-out marks every shift. An
 * outside-schedule check-in is a date of its own, all of its time outside.
 *
 * THE STATE keeps legacy's precedence: a record, then a day off, then an
 * excuse, then leave, then a refusal, else absent — and absent becomes "not
 * yet" until the shift has ended.
 *
 * Records are read from three days before the range, so a duty begun before
 * `from` is spread into it.
 */
class AttendanceDays
{
    private const RECORD_LOOKBACK_DAYS = 3;

    private const SHIFT_LOOKBACK_DAYS = 6;

    public function __construct(
        private readonly ExpectedShifts $shifts,
        private readonly ApprovedLeave $leave,
        private readonly AttendanceCalendar $calendar,
    ) {}

    /**
     * @param  Collection<int, Employee>  $employees
     * @return array<int, array<string, DayFacts>> employee id → date → facts
     */
    public function for(Collection $employees, string $from, string $to): array
    {
        $ids = $employees->map(fn (Employee $e): int => (int) $e->getKey())->values()->all();
        if ($ids === []) {
            return [];
        }

        $tz = $this->calendar->timezone();
        $now = CarbonImmutable::now($tz);
        $today = $now->toDateString();
        $first = CarbonImmutable::parse($from, $tz);
        $last = CarbonImmutable::parse($to, $tz);

        $shifts = $this->shifts->shiftsFor($ids, $first->subDays(self::SHIFT_LOOKBACK_DAYS)->toDateString(), $last->addDays(Duty::MAX_SHIFTS)->toDateString());
        $records = $this->records($ids, $first->subDays(self::RECORD_LOOKBACK_DAYS)->toDateString(), $to);
        $refused = $this->refused($ids, $from, $to);
        $spans = $this->leave->spans($ids, $from, $to);
        $opens = max(0, (int) config('attendance.check_in_opens_minutes', 60));
        $close = max(0, (int) config('attendance.close_after_minutes', 240));
        $grace = max(0, (int) config('attendance.grace_minutes', 15));

        $out = [];
        foreach ($employees as $employee) {
            $id = (int) $employee->getKey();
            $mine = $shifts[$id] ?? [];
            $duties = [];
            foreach (Duty::runs(array_values($mine), $opens, $close) as $duty) {
                $duties[$duty->shiftDate()] = $duty;
            }

            $entries = $this->entries($records->get($id, collect()), $duties, $now, $grace);
            $ends = $employee->termination_date?->toDateString();

            for ($day = $first; $day->lte($last); $day = $day->addDay()) {
                $date = $day->toDateString();
                $shift = ($ends !== null && $date > $ends) ? null : ($mine[$date] ?? null);
                $out[$id][$date] = $this->facts($id, $date, $shift, $entries[$date] ?? null, $refused[$id][$date] ?? [], $spans, $now, $today);
            }
        }

        return $out;
    }

    /**
     * @param  array{0: AttendanceRecord, 1: ?AttendanceRecord, 2: ShiftPresence, 3: int, 4: bool}|null  $entry  in, out, presence, the duty's shift count, whether it satisfies the date's shift
     * @param  list<AttendanceRecord>  $refusedIns
     * @param  array<int, LeaveSpan>  $spans
     */
    private function facts(int $id, string $date, ?ExpectedShift $shift, ?array $entry, array $refusedIns, array $spans, CarbonImmutable $now, string $today): DayFacts
    {
        [$in, $out, $presence, $dutyShifts, $satisfies] = $entry ?? [null, null, null, 0, true];
        $missing = $in !== null && $out === null && $in->status === AttendanceRecordStatus::MissingCheckout;
        $hasRecord = $in !== null && ($presence->present || $missing);
        if (! $hasRecord) {
            [$in, $out, $presence] = [null, null, null];
        }
        // An outside-schedule check-in that does not reach the date's shift
        // (final review I3): the row still shows it, but the shift is judged
        // as if nothing were recorded — absent once it has ended.
        $decides = $hasRecord && ($satisfies || $shift === null);

        $span = $this->spanOn($spans, $id, $date);
        $state = match (true) {
            $decides            => $out !== null ? AttendanceDayState::CheckedOut
                : ($missing ? AttendanceDayState::MissingCheckout : AttendanceDayState::CheckedIn),
            $shift === null     => AttendanceDayState::Off,
            (bool) $span?->isExcuse => AttendanceDayState::Excused,
            $span !== null      => AttendanceDayState::OnLeave,
            // HR threw the day out: it costs the day either way, so it is
            // absent; the state only says what happened.
            $refusedIns !== []  => AttendanceDayState::Rejected,
            default             => AttendanceDayState::Absent,
        };

        // Not yet: the shift has not ended and nothing covers it.
        if ($state === AttendanceDayState::Absent && $shift !== null && $shift->endsAt->gt($now)) {
            $state = AttendanceDayState::NotYet;
        }

        $hours = match (true) {
            ! $hasRecord || $out === null => null,
            // One shift, or a record of its own: its hours, exactly as recorded.
            $dutyShifts <= 1             => $out->work_hours,
            default                      => $presence->workHours,
        };

        return new DayFacts(
            date: $date,
            state: $state,
            shift: $shift,
            in: $in,
            out: $out,
            presence: $hasRecord && ! $missing ? $presence : null,
            hours: $hours,
            span: $span,
            refusedIn: $refusedIns[0] ?? null,
            refusedCount: count($refusedIns),
            counted: $date <= $today && $state !== AttendanceDayState::NotYet,
        );
    }

    /**
     * Each check-in's presence, per date it reaches.
     *
     * @param  Collection<int, AttendanceRecord>  $records
     * @param  array<string, Duty>  $duties  by start date
     *                                       An outside-schedule check-in is the duty's presence where it overlaps
     *                                       the duty that starts on its date (07:59 to 16:00 is the 09:00 shift's,
     *                                       an hour outside); otherwise it is a record of its own that does not
     *                                       satisfy that date's shift (final review I3).
     * @return array<string, array{0: AttendanceRecord, 1: ?AttendanceRecord, 2: ShiftPresence, 3: int, 4: bool}>
     */
    private function entries(Collection $records, array $duties, CarbonImmutable $now, int $grace): array
    {
        $checkOuts = $records->filter(fn (AttendanceRecord $r): bool => $r->type === AttendanceEventType::CheckOut)
            ->groupBy(fn (AttendanceRecord $r): string => $r->shift_date->toDateString())->map->last();

        $entries = [];
        foreach ($records->filter(fn (AttendanceRecord $r): bool => $r->type === AttendanceEventType::CheckIn) as $in) {
            $shiftDate = $in->shift_date->toDateString();
            if (isset($entries[$shiftDate])) {
                continue; // one accepted check-in per shift date; the first stands
            }
            $out = $checkOuts->get($shiftDate);
            $duty = $duties[$shiftDate] ?? null;
            $split = $duty === null ? [] : DutyPresence::split($duty, $in->recorded_at, $out?->recorded_at, $now, $grace);
            $reaches = array_filter($split, static fn (ShiftPresence $p): bool => $p->present) !== [];

            if ($duty === null || ($in->outside_schedule && ! $reaches)) {
                $minutes = intdiv(max(0, ($out?->recorded_at ?? $now)->getTimestamp() - $in->recorded_at->getTimestamp()), 60);
                $entries[$shiftDate] = [$in, $out, new ShiftPresence(true, 0, 0, 0, $minutes, round($minutes / 60, 2)), 1, ! $in->outside_schedule || $duty === null];

                continue;
            }

            foreach ($split as $date => $presence) {
                $entries[$date] = [$in, $out, $presence, count($duty->shifts), true];
            }
        }

        return $entries;
    }

    /**
     * Accepted records by employee, in order.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Collection<int, AttendanceRecord>>
     */
    private function records(array $ids, string $from, string $to): Collection
    {
        return AttendanceRecord::query()
            ->with(['employee.department', 'correctedBy.employee', 'rejectedBy.employee'])
            ->whereIn('employee_id', $ids)->accepted()->inDateRange($from, $to)
            ->orderBy('recorded_at')->orderBy('id')
            ->get()->groupBy('employee_id');
    }

    /**
     * Refused check-ins by employee and shift date, in order.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, list<AttendanceRecord>>>
     */
    private function refused(array $ids, string $from, string $to): array
    {
        $out = [];
        $rows = AttendanceRecord::query()
            ->with(['employee.department', 'rejectedBy.employee'])
            ->whereIn('employee_id', $ids)->checkIns()->rejected()->inDateRange($from, $to)
            ->orderBy('recorded_at')->orderBy('id')->get();
        foreach ($rows as $row) {
            $out[(int) $row->employee_id][$row->shift_date->toDateString()][] = $row;
        }

        return $out;
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
}
