<?php

declare(strict_types=1);

namespace App\Modules\Me\Services;

use App\Modules\Attendance\Contracts\ExpectedShifts;
use App\Modules\Attendance\Enums\AttendanceEventType;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Services\OpenCheckIns;
use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Modules\Attendance\Support\Duty;
use App\Modules\Attendance\Support\ExpectedShift;
use App\Modules\HR\Models\Employee;
use Carbon\CarbonImmutable;

/**
 * The home screen's `duty` card (work shifts piece 4, app spec §4.1): which
 * check-in a check-out now would close, the last one whose check-out was
 * missed, and the caller's duties from the one
 * in progress through the next seven days, each with its window and its close
 * from its shifts' own rules.
 *
 * The app compares "now" with these instants and never works a window out
 * itself: the rules are HR's to read, and the joining of back-to-back shifts
 * into one duty is the resolver's (`Duty::runs`), the same one the check-in
 * rules use.
 */
final class MyDuty
{
    /** How far ahead duties are listed: enough for the phone to work offline. */
    private const AHEAD_DAYS = 7;

    /** From six days back, so a run is cut where every reader cuts it. */
    private const BEHIND_DAYS = 6;

    public function __construct(
        private readonly AttendanceCalendar $calendar,
        private readonly ExpectedShifts $shifts,
        private readonly OpenCheckIns $open,
    ) {}

    /**
     * @return array{open_check_in: array<string, mixed>|null, missed_check_out: array<string, mixed>|null, duties: list<array<string, mixed>>}
     */
    public function for(Employee $employee): array
    {
        $timezone = $this->calendar->timezone();
        $now = CarbonImmutable::now($timezone);
        $today = CarbonImmutable::parse($this->calendar->today(), $timezone);
        $horizon = $now->addDays(self::AHEAD_DAYS);
        $id = (int) $employee->getKey();

        $all = $this->shifts->shiftsFor(
            [$id],
            $today->subDays(self::BEHIND_DAYS)->toDateString(),
            $today->addDays(self::AHEAD_DAYS + Duty::MAX_SHIFTS)->toDateString(),
        )[$id] ?? [];

        $duties = array_values(array_filter(
            Duty::runs(array_values($all)),
            static fn (Duty $d): bool => $d->closesAt->gt($now) && $d->opensAt->lt($horizon),
        ));

        return [
            'open_check_in'    => $this->openCheckIn($employee, $now),
            'missed_check_out' => $this->missedCheckOut($employee, $now),
            'duties'           => $this->present($id, $duties),
        ];
    }

    /** @return array<string, mixed>|null */
    private function openCheckIn(Employee $employee, CarbonImmutable $now): ?array
    {
        $in = $this->open->forCheckOut($employee, $now);
        if ($in === null) {
            return null;
        }
        $closes = $this->open->closeOf($in);
        // forCheckOut judges "open" on moments: a check-in whose close has
        // passed is not one a check-out now would close.
        if ($closes->lt($now)) {
            return null;
        }

        return [
            'id'               => $in->id,
            'recorded_at'      => $in->recorded_at->toIso8601String(),
            'shift_date'       => $in->shift_date->toDateString(),
            'outside_schedule' => (bool) $in->outside_schedule,
            'closes_at'        => $closes->setTimezone($this->calendar->timezone())->toIso8601String(),
        ];
    }

    /**
     * The latest check-in whose close passed with no check-out: the person
     * can no longer close it, so the app says so and sends them to HR.
     *
     * @return array<string, mixed>|null
     */
    private function missedCheckOut(Employee $employee, CarbonImmutable $now): ?array
    {
        $in = $this->open->missedBefore($employee, $now);
        if ($in === null) {
            return null;
        }

        return [
            'id'               => $in->id,
            'recorded_at'      => $in->recorded_at->toIso8601String(),
            'shift_date'       => $in->shift_date->toDateString(),
            'outside_schedule' => (bool) $in->outside_schedule,
            'closed_at'        => $this->open->closeOf($in)->setTimezone($this->calendar->timezone())->toIso8601String(),
        ];
    }

    /**
     * @param  list<Duty>  $duties
     * @return list<array<string, mixed>>
     */
    private function present(int $employeeId, array $duties): array
    {
        if ($duties === []) {
            return [];
        }

        $dates = array_map(static fn (Duty $d): string => $d->shiftDate(), $duties);
        // Any accepted record of the duty's date takes it, outside-schedule
        // ones included (an early check-in), as the check-in rule counts them
        // (AttendanceService: one check-in per shift date).
        $records = AttendanceRecord::forEmployee($employeeId)->accepted()
            // The model's own date scope: shift_date is stored as a date-time.
            ->inDateRange(min($dates), max($dates))
            ->orderBy('recorded_at')->orderBy('id')
            ->get()
            ->groupBy(static fn (AttendanceRecord $r): string => $r->shift_date->toDateString());

        $timezone = $this->calendar->timezone();
        $iso = static fn (CarbonImmutable $t): string => $t->setTimezone($timezone)->toIso8601String();

        return array_map(static function (Duty $d) use ($records, $iso): array {
            $mine = $records->get($d->shiftDate(), collect());
            $in = $mine->first(static fn (AttendanceRecord $r): bool => $r->type === AttendanceEventType::CheckIn);
            $out = $mine->first(static fn (AttendanceRecord $r): bool => $r->type === AttendanceEventType::CheckOut);

            return [
                'shift_date'     => $d->shiftDate(),
                'opens_at'       => $iso($d->opensAt),
                'starts_at'      => $iso($d->startsAt()),
                'ends_at'        => $iso($d->endsAt()),
                'closes_at'      => $iso($d->closesAt),
                'shifts'         => array_map(static fn (ExpectedShift $s): array => $s->toArray(), $d->shifts),
                'check_in_time'  => $in?->recorded_at->toIso8601String(),
                'check_out_time' => $out?->recorded_at->toIso8601String(),
            ];
        }, $duties);
    }
}
