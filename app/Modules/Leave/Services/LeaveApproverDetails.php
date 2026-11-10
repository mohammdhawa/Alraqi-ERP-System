<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveRequest;
use Illuminate\Support\Collection;

/**
 * What an approver reads of a leave beyond the leave itself: its balance
 * figures (app slice 5, P1). Being on the chain is what admits them, so no
 * permission is asked — `leave.balances.view` is HR's whole-list reach.
 *
 * `after_request` is not clamped: pending requests reserve nothing, so two
 * can each fit and pass the balance together, and a negative figure is how
 * the approver is told.
 */
class LeaveApproverDetails
{
    public function __construct(private readonly LeaveBalances $balances) {}

    /**
     * The `details` of a page of inbox rows, keyed by leave id: one balance
     * read per start year, so a page costs the same at any length. With
     * $decided no row carries a balance: the caller has already decided, even
     * if the leave still waits on a later step.
     *
     * @param  Collection<int, LeaveRequest>  $leaves
     * @return array<int, array<string, mixed>>
     */
    public function rowDetails(Collection $leaves, bool $decided): array
    {
        $figures = [];
        $measured = $decided ? collect() : $leaves->filter($this->hasBalance(...))->keyBy('id');

        foreach ($measured->groupBy(static fn (LeaveRequest $leave): int => $leave->start_date->year) as $year => $group) {
            $figures[$year] = $this->balances->forMany($group->pluck('employee_id')->all(), (int) $year);
        }

        return $leaves->mapWithKeys(fn (LeaveRequest $leave): array => [$leave->id => [
            'start_date'     => $leave->start_date->toDateString(),
            'end_date'       => $leave->end_date->toDateString(),
            'requested_days' => $leave->requested_days,
            'balance'        => $measured->has($leave->getKey())
                ? $this->figures($leave, $figures[$leave->start_date->year][$leave->employee_id])
                : null,
        ]])->all();
    }

    /**
     * @return array{year: int, remaining_days: int, after_request: int}|null
     */
    public function balanceOf(LeaveRequest $leave): ?array
    {
        return $this->hasBalance($leave)
            ? $this->figures($leave, $this->balances->for((int) $leave->employee_id, $leave->start_date->year))
            : null;
    }

    /**
     * The `approver_view` of one leave for $user, or null when they hold no
     * step on it. The requester is left out by name: they can hold a step,
     * skipped because it would have been theirs to decide.
     *
     * @return array{job_title: ?array{id: int, name: string}, department: ?array{id: int, name: string}, balance: ?array{year: int, remaining_days: int, after_request: int}}|null
     */
    public function viewFor(LeaveRequest $leave, User $user): ?array
    {
        $viewer = $user->employee_id === null ? null : (int) $user->employee_id;

        if ($viewer === null || $viewer === (int) $leave->employee_id) {
            return null;
        }

        if (! $leave->approval?->steps->contains('approver_id', $viewer)) {
            return null;
        }

        $leave->loadMissing('employee.jobTitle', 'employee.department');
        $title = $leave->employee?->jobTitle;
        $unit = $leave->employee?->department;

        return [
            'job_title'  => $title === null ? null : ['id' => $title->id, 'name' => $title->name],
            'department' => $unit === null ? null : ['id' => $unit->id, 'name' => $unit->name],
            'balance'    => $this->balanceOf($leave),
        ];
    }

    /**
     * A balance only means something before the decision, and only for a
     * leave that takes from it — the leave's own flag, not its type's.
     */
    private function hasBalance(LeaveRequest $leave): bool
    {
        return $leave->status === LeaveStatus::Pending && $leave->deducts_balance;
    }

    /**
     * @return array{year: int, remaining_days: int, after_request: int}
     */
    private function figures(LeaveRequest $leave, BalanceFigures $balance): array
    {
        return [
            'year'           => $balance->year,
            'remaining_days' => $balance->remaining,
            'after_request'  => $balance->remaining - $leave->requested_days,
        ];
    }
}
