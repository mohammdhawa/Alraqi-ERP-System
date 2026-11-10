<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Services;

use App\Modules\Attendance\Contracts\ExpectedShifts;
use App\Modules\Attendance\Support\Duty;
use App\Modules\HR\Models\Employee;
use App\Modules\Scheduling\Enums\ShiftChangeOrigin;
use App\Modules\Scheduling\Models\ShiftChange;
use App\Modules\Scheduling\Models\ShiftSwap;

/**
 * What a duty exchange writes (work shifts piece 2, spec §6.3): each guard's
 * given duty becomes days off, and he takes the other's shifts on the
 * other's dates. Where the two duties share a date, the taken shift wins.
 * Read from the duties as they stand when asked — at filing for the check,
 * at approval for the rows.
 */
class ShiftSwapPlan
{
    public function __construct(private readonly ExpectedShifts $shifts) {}

    /** @return array{0: ?Duty, 1: ?Duty} the requester's duty and the partner's */
    public function duties(ShiftSwap $swap): array
    {
        return [
            $this->dutyOf((int) $swap->requester_id, $swap->requester_duty_date->toDateString()),
            $this->dutyOf((int) $swap->partner_id, $swap->partner_duty_date->toDateString()),
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
