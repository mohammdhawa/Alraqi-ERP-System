<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Services;

use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Modules\Attendance\Support\Duty;
use App\Modules\HR\Models\Employee;
use App\Modules\Scheduling\Contracts\BlockingLeave;
use App\Modules\Scheduling\Enums\WorkScheduleKind;
use App\Modules\Scheduling\Models\EmployeeWorkSchedule;
use App\Modules\Scheduling\Models\ShiftChange;
use App\Modules\Scheduling\Models\ShiftSwap;
use App\Shared\Services\ManagementChain;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The rules a duty exchange must pass (work shifts piece 2, spec §6.2), at
 * filing and again before approval — the second time without the manager
 * rule, since the approver was fixed at filing. The first broken rule's
 * message, in Arabic, or null.
 */
class ShiftSwapCheck
{
    public function __construct(
        private readonly ShiftSwapPlan $plan,
        private readonly ScheduleResolver $resolver,
        private readonly ManagementChain $chain,
        private readonly BlockingLeave $leave,
        private readonly AttendanceCalendar $calendar,
    ) {}

    public function violation(ShiftSwap $swap, bool $atFiling): ?string
    {
        // 1. Someone else.
        if ((int) $swap->requester_id === (int) $swap->partner_id) {
            return 'لا يمكنك تبديل مناوبة مع نفسك.';
        }

        $requester = Employee::withTrashed()->findOrFail($swap->requester_id);
        $partner = Employee::withTrashed()->findOrFail($swap->partner_id);
        [$mine, $theirs] = $this->plan->duties($swap);
        $dates = $this->dates($swap, $mine, $theirs);

        // A partner who will have left before the last day he works under the
        // exchange — his own duty or the one he takes (final review I5).
        if ($partner->trashed() || ($partner->termination_date !== null && $partner->termination_date->toDateString() < $dates[array_key_last($dates)])) {
            return 'الزميل المحدد ليس على رأس عمله.';
        }

        // 2. Both guards, on every date of both duties.
        if (! $this->onCycle($requester, $dates) || ! $this->onCycle($partner, $dates)) {
            return 'تبديل المناوبات متاح لموظفي الحراسة فقط.';
        }

        // 3. The same direct manager — at filing only.
        if ($atFiling) {
            $manager = $this->chain->resolve($requester)?->getKey();
            if ($manager === null || $manager !== $this->chain->resolve($partner)?->getKey()) {
                return 'يجب أن يكون للزميل المدير المباشر نفسه.';
            }
        }

        // 4. Two duty starts, two different duties, neither opened.
        if ($mine === null || $theirs === null) {
            return 'التاريخ المحدد ليس بداية مناوبة.';
        }
        if ($mine->startsAt()->equalTo($theirs->startsAt()) && $mine->endsAt()->equalTo($theirs->endsAt())) {
            return 'لا يمكن تبديل المناوبة بنفسها.';
        }
        $now = CarbonImmutable::now($this->calendar->timezone());
        if ($mine->opensAt->lte($now) || $theirs->opensAt->lte($now)) {
            return 'لا يمكن تبديل مناوبة بدأت.';
        }

        // 5. No other live exchange of either duty; no change on a date it writes.
        if ($this->inAnotherSwap($swap, (int) $requester->getKey(), $mine->shiftDate()) || $this->inAnotherSwap($swap, (int) $partner->getKey(), $theirs->shiftDate())) {
            return 'إحدى المناوبتين ضمن طلب تبديل آخر.';
        }
        $overrides = $this->plan->overrides($swap);
        foreach ($overrides as $employeeId => $written) {
            $onWritten = ShiftChange::query()->where('employee_id', $employeeId)->where(function (Builder $q) use ($written): void {
                foreach (array_keys($written) as $date) {
                    $q->orWhereDate('date', $date);
                }
            });
            if ($onWritten->exists()) {
                return 'يوجد تعديل على أحد أيام التبديل.';
            }
        }

        // 6. No pending or approved leave on either duty — the duties' own
        // dates, not the days between them (final review I3).
        foreach ([$requester, $partner] as $person) {
            foreach ([$mine, $theirs] as $duty) {
                if ($this->leave->blocksAny((int) $person->getKey(), $duty->shiftDate(), $duty->shifts[array_key_last($duty->shifts)]->date)) {
                    return 'توجد إجازة تتداخل مع إحدى المناوبتين.';
                }
            }
        }

        // 7. No run longer than six for either.
        return $this->resolver->runViolation($requester, $overrides[(int) $requester->getKey()])
            ?? $this->resolver->runViolation($partner, $overrides[(int) $partner->getKey()]);
    }

    /**
     * Every date of both duties, sorted — the named date where a duty is not
     * there, so rule 2 can still be judged.
     *
     * @return list<string>
     */
    private function dates(ShiftSwap $swap, ?Duty $mine, ?Duty $theirs): array
    {
        $dates = [];
        foreach ([[$mine, $swap->requester_duty_date], [$theirs, $swap->partner_duty_date]] as [$duty, $named]) {
            foreach ($duty === null ? [$named->toDateString()] : array_map(static fn ($s): string => $s->date, $duty->shifts) as $date) {
                $dates[$date] = true;
            }
        }
        $dates = array_keys($dates);
        sort($dates);

        return $dates;
    }

    /** @param  list<string>  $dates */
    private function onCycle(Employee $employee, array $dates): bool
    {
        foreach ($dates as $date) {
            $span = EmployeeWorkSchedule::query()->where('employee_id', $employee->getKey())->inForceOn($date)->with('schedule')->first();
            if ($span === null || $span->schedule->kind !== WorkScheduleKind::Cycle) {
                return false;
            }
        }

        return true;
    }

    private function inAnotherSwap(ShiftSwap $swap, int $employeeId, string $dutyDate): bool
    {
        return ShiftSwap::query()->live()
            ->when($swap->exists, fn (Builder $q) => $q->whereKeyNot($swap->getKey()))
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $r) => $r->where('requester_id', $employeeId)->whereDate('requester_duty_date', $dutyDate))
                ->orWhere(fn (Builder $p) => $p->where('partner_id', $employeeId)->whereDate('partner_duty_date', $dutyDate)))
            ->exists();
    }
}
