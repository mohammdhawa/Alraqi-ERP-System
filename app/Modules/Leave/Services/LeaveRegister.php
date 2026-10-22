<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Modules\Attendance\Services\AttendancePopulation;
use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Shared\Support\RequestRowScopes;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * HR's leave register — legacy's LeaveRequestController::register()
 * (LEAVE_BUILD.md §6): every leave in the caller's `leave.requests.view`
 * reach (the employee's CURRENT unit, D6) overlapping a window.
 *
 * DAYS ARE COUNTED INSIDE THE WINDOW. A leave from 28 August to 5 September
 * is in both months' registers and contributes to each only its own days:
 * `days_in_range` in working days (what a balance is spent in),
 * `calendar_days_in_range` the raw span. `requested_days` rides along.
 *
 * THE SUMMARY IS OVER THE WHOLE FILTERED SET, computed before paging — one
 * that changed when you turned the page would be worse than none. Counted in
 * PHP, because every figure is clipped and measured by the calendar; the set
 * is bounded by the 366-day window.
 */
class LeaveRegister
{
    public function __construct(
        private readonly AttendanceCalendar $calendar,
        private readonly LeaveRequestQuery $requests,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  validated, with `from` and `to`
     * @return array{rows: LengthAwarePaginator, summary: array<string, mixed>}
     */
    public function page(User $user, array $filters, int $perPage): array
    {
        $from = (string) $filters['from'];
        $to = (string) $filters['to'];
        $query = $this->query($user, $filters, $from, $to);

        $summary = $this->summary((clone $query)->reorder(), $from, $to);

        $rows = $query->with(LeaveRequestQuery::renderRelations())->paginate($perPage);
        $rows->getCollection()->each(function (LeaveRequest $leave) use ($from, $to): void {
            [$days, $calendar] = $this->inWindow($leave, $from, $to);
            $leave->setAttribute('days_in_range', $days);
            $leave->setAttribute('calendar_days_in_range', $calendar);
        });

        return ['rows' => $rows, 'summary' => $summary];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<LeaveRequest>
     */
    private function query(User $user, array $filters, string $from, string $to): Builder
    {
        $scope = RequestRowScopes::forUser($user, 'leave.requests.view');

        $query = LeaveRequest::query()
            ->overlapping($from, $to)
            ->whereHas('employee', static function (Builder $employees) use ($scope, $filters): void {
                $scope->applyTo(AttendancePopulation::filter($employees, $filters));
            });

        $this->requests->applyFilters($query, $filters);

        $sort = $filters['sort'] ?? 'start_date';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $key = ltrim($sort, '-');

        if ($key === 'employee_name') {
            foreach (['first_name', 'middle_name', 'last_name'] as $column) {
                $query->orderBy(Employee::withTrashed()->select($column)->whereColumn('employees.id', 'leave_requests.employee_id'), $direction);
            }
        } else {
            $query->orderBy($key, $direction);
        }

        return $query->orderBy('id', $direction);
    }

    /**
     * @param  Builder<LeaveRequest>  $query
     * @return array<string, mixed>
     */
    private function summary(Builder $query, string $from, string $to): array
    {
        $rows = $query->get(['id', 'employee_id', 'leave_type_id', 'deducts_balance', 'origin', 'status', 'start_date', 'end_date']);
        $types = LeaveType::withTrashed()->whereKey($rows->pluck('leave_type_id')->unique()->all())->get()->keyBy('id');

        $totals = ['days' => 0, 'calendar_days' => 0, 'deducted_days' => 0, 'excuses' => 0];
        $employees = $byType = $byStatus = [];

        foreach ($rows as $row) {
            [$days, $calendar] = $this->inWindow($row, $from, $to);

            $employees[$row->employee_id] = true;
            $totals['days'] += $days;
            $totals['calendar_days'] += $calendar;
            $totals['deducted_days'] += $row->deducts_balance ? $days : 0;
            $totals['excuses'] += $row->isExcuse() ? 1 : 0;

            $status = (string) $row->status->value;
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;

            $type = $types->get($row->leave_type_id);
            $byType[$row->leave_type_id] ??= [
                'leave_type_id'   => (int) $row->leave_type_id,
                'name'            => $type?->name_ar,
                'deducts_balance' => (bool) $type?->deducts_balance,
                'requests'        => 0,
                'employees'       => [],
                'days'            => 0,
            ];
            $byType[$row->leave_type_id]['requests']++;
            $byType[$row->leave_type_id]['days'] += $days;
            $byType[$row->leave_type_id]['employees'][$row->employee_id] = true;
        }

        $byType = collect($byType)
            ->map(static fn (array $type): array => array_replace($type, ['employees' => count($type['employees'])]))
            ->sortByDesc('days')
            ->values()
            ->all();

        return [
            'employees' => count($employees),
            'requests'  => $rows->count(),
        ] + $totals + [
            'by_type'   => $byType,
            'by_status' => $byStatus,
        ];
    }

    /**
     * The leave clipped to the window: [working days, calendar days].
     *
     * @return array{0: int, 1: int}
     */
    private function inWindow(LeaveRequest $leave, string $from, string $to): array
    {
        $start = max($leave->start_date->toDateString(), $from);
        $end = min($leave->end_date->toDateString(), $to);

        if ($end < $start) {
            return [0, 0];
        }

        return [
            $this->calendar->workingDaysBetween($start, $end),
            (int) Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1,
        ];
    }
}
