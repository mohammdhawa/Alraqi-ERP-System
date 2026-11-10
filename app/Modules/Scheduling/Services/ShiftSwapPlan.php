<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Services;

use App\Modules\Attendance\Contracts\ExpectedShifts;
use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Modules\Attendance\Support\Duty;
use App\Modules\HR\Models\Employee;
use App\Modules\Scheduling\Enums\ShiftChangeOrigin;
use App\Modules\Scheduling\Enums\ShiftSwapStatus;
use App\Modules\Scheduling\Models\ShiftChange;
use App\Modules\Scheduling\Models\ShiftSwap;
use Carbon\CarbonImmutable;

/**
 * What a duty exchange writes (work shifts piece 2, spec §6.3): each guard's
 * given duty becomes days off, and he takes the other's shifts on the
 * other's dates. Where the two duties share a date, the taken shift wins.
 * Read from the duties as they stand when asked — at filing for the check,
 * at approval for the rows.
 */
class ShiftSwapPlan
{
    /** @var array<int, array{0: ?Duty, 1: ?Duty}> shown(), loaded for a page by preload() */
    private array $preloaded = [];

    public function __construct(
        private readonly ExpectedShifts $shifts,
        private readonly AttendanceCalendar $calendar,
    ) {}

    /** @return array{0: ?Duty, 1: ?Duty} the requester's duty and the partner's */
    public function duties(ShiftSwap $swap): array
    {
        return [
            $this->dutyOf((int) $swap->requester_id, $swap->requester_duty_date->toDateString()),
            $this->dutyOf((int) $swap->partner_id, $swap->partner_duty_date->toDateString()),
        ];
    }

    /**
     * The two duties as they are worked now, for display: by their owners
     * until approved, by the other guard once the exchange has written them.
     *
     * @return array{0: ?Duty, 1: ?Duty} the requester's named duty and the partner's
     */
    public function shown(ShiftSwap $swap): array
    {
        if (array_key_exists((int) $swap->getKey(), $this->preloaded)) {
            return $this->preloaded[(int) $swap->getKey()];
        }

        [[$first, $firstDate], [$second, $secondDate]] = $this->shownBy($swap);

        return [$this->dutyOf($first, $firstDate), $this->dutyOf($second, $secondDate)];
    }

    /**
     * shown() for a whole page in one resolver read (final review I6): every
     * person's shifts over the page's dates, cut into duties in memory, from
     * six days back as every reader cuts them. Bound scoped, so the resource
     * that renders the page reads what this loaded.
     *
     * @param  iterable<ShiftSwap>  $swaps
     */
    public function preload(iterable $swaps): void
    {
        $wanted = [];
        foreach ($swaps as $swap) {
            $wanted[(int) $swap->getKey()] = $this->shownBy($swap);
        }
        if ($wanted === []) {
            return;
        }

        $pairs = array_merge(...array_values($wanted));
        $dates = array_column($pairs, 1);
        $timezone = $this->calendar->timezone();
        $from = CarbonImmutable::parse(min($dates), $timezone)->subDays(6)->toDateString();
        $to = CarbonImmutable::parse(max($dates), $timezone)->addDays(Duty::MAX_SHIFTS)->toDateString();
        $opens = max(0, (int) config('attendance.check_in_opens_minutes', 60));
        $close = max(0, (int) config('attendance.close_after_minutes', 240));

        $duties = [];
        foreach ($this->shifts->shiftsFor(array_values(array_unique(array_column($pairs, 0))), $from, $to) as $employeeId => $shifts) {
            foreach (Duty::runs(array_values($shifts), $opens, $close) as $duty) {
                $duties[$employeeId][$duty->shiftDate()] = $duty;
            }
        }

        foreach ($wanted as $id => [[$first, $firstDate], [$second, $secondDate]]) {
            $this->preloaded[$id] = [$duties[$first][$firstDate] ?? null, $duties[$second][$secondDate] ?? null];
        }
    }

    /**
     * Who works each named duty now: its owner until approved, the other
     * guard once the exchange has written it.
     *
     * @return array{0: array{0: int, 1: string}, 1: array{0: int, 1: string}}
     */
    private function shownBy(ShiftSwap $swap): array
    {
        $approved = $swap->status === ShiftSwapStatus::Approved;

        return [
            [(int) ($approved ? $swap->partner_id : $swap->requester_id), $swap->requester_duty_date->toDateString()],
            [(int) ($approved ? $swap->requester_id : $swap->partner_id), $swap->partner_duty_date->toDateString()],
        ];
    }

    /**
     * Each guard's dates → the shift id he will work, or null for a day off.
     * Empty when either date is not the start of a duty.
     *
     * @return array<int, array<string, ?int>>
     */
    public function overrides(ShiftSwap $swap): array
    {
        [$mine, $theirs] = $this->duties($swap);
        if ($mine === null || $theirs === null) {
            return [];
        }

        return [
            (int) $swap->requester_id => $this->trade($mine, $theirs),
            (int) $swap->partner_id   => $this->trade($theirs, $mine),
        ];
    }

    /** Write the exchange's changes, origin Swap. Inside the approval's transaction. */
    public function write(ShiftSwap $swap): void
    {
        foreach ($this->overrides($swap) as $employeeId => $dates) {
            foreach ($dates as $date => $shiftId) {
                ShiftChange::create([
                    'employee_id'   => $employeeId,
                    'date'          => $date,
                    'shift_id'      => $shiftId,
                    'origin'        => ShiftChangeOrigin::Swap,
                    'shift_swap_id' => $swap->getKey(),
                    'created_by'    => $swap->created_by,
                ]);
            }
        }
    }

    /** @return array<string, ?int> the given duty's dates off, then the taken duty's shifts */
    private function trade(Duty $given, Duty $taken): array
    {
        $out = [];
        foreach ($given->shifts as $shift) {
            $out[$shift->date] = null;
        }
        foreach ($taken->shifts as $shift) {
            $out[$shift->date] = $shift->shiftId;
        }

        return $out;
    }

    private function dutyOf(int $employeeId, string $date): ?Duty
    {
        $employee = Employee::withTrashed()->find($employeeId);

        return $employee === null ? null : $this->shifts->dutyOn($employee, $date);
    }
}
