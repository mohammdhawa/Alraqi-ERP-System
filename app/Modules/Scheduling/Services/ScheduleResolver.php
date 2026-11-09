<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Services;

use App\Modules\Attendance\Contracts\ExpectedShifts;
use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Modules\Attendance\Support\Duty;
use App\Modules\Attendance\Support\ExpectedShift;
use App\Modules\HR\Models\Employee;
use App\Modules\Scheduling\Enums\WorkScheduleKind;
use App\Modules\Scheduling\Models\EmployeeWorkSchedule;
use App\Modules\Scheduling\Models\WorkSchedule;
use App\Modules\Scheduling\Models\WorkScheduleDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Attendance's ExpectedShifts, from the Scheduling tables (work shifts spec §4).
 *
 * A person's shift on a date: the span in force on it, else the default
 * schedule (config attendance.default_schedule). Weekly positions are the weekday; a
 * cycle's are counted from the span's cycle_start_date, wrapping in both
 * directions. Duties join back-to-back shifts, at most three.
 *
 * BATCH. shiftsFor() loads every span of the ids that touches the range,
 * with their schedules, days and shifts, in four queries, and resolves the
 * rest in memory (spec §4.4).
 */
class ScheduleResolver implements ExpectedShifts
{
    /** How far back to look for the start of the duty holding a date. */
    private const LOOKBACK_DAYS = 6;

    private ?WorkSchedule $default = null;

    public function __construct(private readonly AttendanceCalendar $calendar) {}

    public function shiftsFor(array $employeeIds, string $from, string $to): array
    {
        $ids = array_values(array_unique(array_map('intval', $employeeIds)));
        $spans = $this->spansFor($ids, $from, $to);

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = $this->shiftsOf($spans->get($id, collect()), $from, $to);
        }

        return $out;
    }

    /**
     * The calendar view (GET /api/scheduling/calendar): every date, with its
     * shift or null for a day off, and where it came from. Scheduling's own —
     * not on the contract, which leaves days off out.
     *
     * @param  array<int, int>  $employeeIds
     * @return array<int, list<array{date: string, shift: ?ExpectedShift, source: string}>>
     */
    public function calendarFor(array $employeeIds, string $from, string $to): array
    {
        $ids = array_values(array_unique(array_map('intval', $employeeIds)));
        $spans = $this->spansFor($ids, $from, $to);
        $timezone = $this->calendar->timezone();

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = [];
            for ($day = CarbonImmutable::parse($from, $timezone), $last = CarbonImmutable::parse($to, $timezone); $day->lte($last); $day = $day->addDay()) {
                [$shift, $source] = $this->resolve($spans->get($id, collect()), $day);
                $out[$id][] = ['date' => $day->toDateString(), 'shift' => $shift, 'source' => $source];
            }
        }

        return $out;
    }

    public function dutiesHolding(Employee $employee, CarbonInterface $moment): array
    {
        $at = CarbonImmutable::instance($moment)->setTimezone($this->calendar->timezone());

        $holding = array_values(array_filter(
            $this->dutiesAround($employee, $at->subDays(self::LOOKBACK_DAYS)->toDateString(), $at->addDay()->toDateString()),
            static fn (Duty $duty): bool => $duty->holds($at),
        ));
        usort($holding, static fn (Duty $a, Duty $b): int => $a->startsAt() <=> $b->startsAt());

        return $holding;
    }

    public function dutyOn(Employee $employee, string $shiftDate): ?Duty
    {
        $date = CarbonImmutable::parse($shiftDate, $this->calendar->timezone());

        foreach ($this->dutiesAround($employee, $date->subDays(self::LOOKBACK_DAYS)->toDateString(), $date->addDays(Duty::MAX_SHIFTS)->toDateString()) as $duty) {
            if ($duty->shiftDate() === $shiftDate) {
                return $duty;
            }
        }

        return null;
    }

    public function workingDaysBetween(Employee $employee, string $from, string $to): int
    {
        if ($from > $to) {
            return 0;
        }

        return count($this->shiftsFor([(int) $employee->getKey()], $from, $to)[(int) $employee->getKey()]);
    }

    /** @return list<Duty> */
    private function dutiesAround(Employee $employee, string $from, string $to): array
    {
        $opens = max(0, (int) config('attendance.check_in_opens_minutes', 60));
        $close = max(0, (int) config('attendance.close_after_minutes', 240));

        $duties = Duty::runs(array_values($this->shiftsFor([(int) $employee->getKey()], $from, $to)[(int) $employee->getKey()]), $opens, $close);

        return $duties;
    }

    /**
     * @param  Collection<int, EmployeeWorkSchedule>  $spans
     * @return array<string, ExpectedShift>
     */
    private function shiftsOf(Collection $spans, string $from, string $to): array
    {
        $out = [];
        $timezone = $this->calendar->timezone();
        for ($day = CarbonImmutable::parse($from, $timezone), $last = CarbonImmutable::parse($to, $timezone); $day->lte($last); $day = $day->addDay()) {
            $shift = $this->shiftOn($spans, $day);
            if ($shift !== null) {
                $out[$shift->date] = $shift;
            }
        }

        return $out;
    }

    /** @param  Collection<int, EmployeeWorkSchedule>  $spans */
    private function shiftOn(Collection $spans, CarbonImmutable $day): ?ExpectedShift
    {
        return $this->resolve($spans, $day)[0];
    }

    /**
     * @param  Collection<int, EmployeeWorkSchedule>  $spans
     * @return array{0: ?ExpectedShift, 1: string} the shift (null: a day off) and its source
     */
    private function resolve(Collection $spans, CarbonImmutable $day): array
    {
        $date = $day->toDateString();
        $span = $spans->first(fn (EmployeeWorkSchedule $s): bool => $s->from_date->toDateString() <= $date
            && ($s->to_date === null || $s->to_date->toDateString() >= $date));

        if ($span !== null) {
            return [$this->fromSchedule($span->schedule, $day, $span->cycle_start_date?->toDateString(), 'assignment'), 'assignment'];
        }

        $default = $this->defaultSchedule();

        return [$this->fromSchedule($default, $day, null, 'default'), 'default'];
    }

    private function fromSchedule(WorkSchedule $schedule, CarbonImmutable $day, ?string $cycleStart, string $source): ?ExpectedShift
    {
        if ($schedule->kind === WorkScheduleKind::Weekly) {
            $position = $day->dayOfWeek;
        } else {
            $offset = (int) round(CarbonImmutable::parse((string) $cycleStart, $this->calendar->timezone())->diffInDays($day, false));
            $position = (($offset % $schedule->cycle_days) + $schedule->cycle_days) % $schedule->cycle_days;
        }

        $shift = $schedule->days->first(fn (WorkScheduleDay $d): bool => $d->position === $position)?->shift;
        if ($shift === null) {
            return null;
        }

        [$hour, $minute] = array_map('intval', explode(':', (string) $shift->starts_at));
        $startsAt = $day->setTime($hour, $minute);

        return new ExpectedShift($day->toDateString(), $startsAt, $startsAt->addMinutes($shift->duration_minutes), $shift->code, $shift->name, $source);
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Collection<int, EmployeeWorkSchedule>>
     */
    private function spansFor(array $ids, string $from, string $to): Collection
    {
        return EmployeeWorkSchedule::query()
            ->whereIn('employee_id', $ids)
            ->whereDate('from_date', '<=', $to)
            ->where(fn ($q) => $q->whereNull('to_date')->orWhereDate('to_date', '>=', $from))
            ->with('schedule.days.shift')
            ->get()
            ->groupBy('employee_id');
    }

    /**
     * The schedule of everyone with none assigned. Missing — an unset or
     * mistyped code, or SchedulingSeeder not run — is a deploy error, and
     * fails loudly rather than quietly leaving everyone without a working day.
     */
    private function defaultSchedule(): WorkSchedule
    {
        if ($this->default === null) {
            $code = (string) config('attendance.default_schedule');
            $this->default = WorkSchedule::query()->where('code', $code)->with('days.shift')->first()
                ?? throw new \RuntimeException("The default work schedule [{$code}] does not exist: set attendance.default_schedule and run SchedulingSeeder.");
        }

        return $this->default;
    }
}
