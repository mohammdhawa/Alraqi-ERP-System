<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeaveRequest;

/**
 * The leave balance — legacy's User::annualLeaveDays(), usedLeaveDays(),
 * nonDeductingLeaveDays() and remainingLeaveDays() (LEAVE_BUILD.md §3).
 *
 * COMPUTED, NEVER STORED: allocation (+ adjustment) − approved deducting days
 * of leave starting in the year. PENDING LEAVE RESERVES NOTHING — legacy
 * checks the balance when a request is filed and again at its final approval,
 * and that second check is what stops two pending requests from overdrawing
 * it together.
 *
 * `forMany()` measures a page of people with one balance query and two
 * grouped sums, so HR's list costs the same at any length.
 */
class LeaveBalances
{
    public function for(int $employeeId, int $year): BalanceFigures
    {
        return $this->forMany([$employeeId], $year)[$employeeId];
    }

    public function remaining(int $employeeId, int $year): int
    {
        return $this->for($employeeId, $year)->remaining;
    }

    /**
     * @param  array<int, int>  $employeeIds
     * @return array<int, BalanceFigures> keyed by employee id
     */
    public function forMany(array $employeeIds, int $year): array
    {
        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));

        if ($employeeIds === []) {
            return [];
        }

        $rows = LeaveBalance::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('year', $year)
            ->get()
            ->keyBy('employee_id');

        $used = $this->sums($employeeIds, $year, deducting: true);
        $free = $this->sums($employeeIds, $year, deducting: false);
        $default = (int) config('leave.annual_days', 15);

        $figures = [];

        foreach ($employeeIds as $id) {
            /** @var LeaveBalance|null $row */
            $row = $rows->get($id);
            $allocated = $row?->totalDays() ?? $default;
            $spent = $used[$id] ?? 0;

            $figures[$id] = new BalanceFigures(
                year: $year,
                allocated: $allocated,
                adjustment: $row?->adjustment_days ?? 0,
                used: $spent,
                remaining: max(0, $allocated - $spent),
                overBalance: max(0, $spent - $allocated),
                nonDeducting: $free[$id] ?? 0,
                isCustom: $row !== null,
                note: $row?->note,
            );
        }

        return $figures;
    }

    /**
     * Approved days per employee for the year, by the snapshot each leave
     * took at filing.
     *
     * @param  array<int, int>  $employeeIds
     * @return array<int, int>
     */
    private function sums(array $employeeIds, int $year, bool $deducting): array
    {
        return LeaveRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->approved()
            ->deducting($deducting)
            ->startingInYear($year)
            ->groupBy('employee_id')
            ->selectRaw('employee_id, SUM(requested_days) as days')
            ->pluck('days', 'employee_id')
            ->map(static fn (mixed $days): int => (int) $days)
            ->all();
    }
}
