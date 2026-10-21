<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Modules\Attendance\Services\AttendancePopulation;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\LeaveRequest;
use App\Shared\Support\RequestRowScopes;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who is on approved leave on one day — legacy's `/approved-leave-users`
 * (owner, 2026-10-20, parity audit X2): every approved request AND HR excuse
 * whose span covers the day, for the employees the caller's
 * `attendance.records.view` reaches — it is an attendance roster, as legacy
 * gated it on the attendance reports.
 *
 * Departed employees (soft-deleted or terminated) are left out, as on the
 * daily board (parity audit C-8).
 */
class OnLeaveRoster
{
    /**
     * @param  array<string, mixed>  $filters  validated, with `date`
     */
    public function page(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        $scope = RequestRowScopes::forUser($user, 'attendance.records.view');
        $date = (string) $filters['date'];

        $query = LeaveRequest::query()
            ->approved()
            ->overlapping($date, $date)
            ->whereHas('employee', static function (Builder $employees) use ($scope, $filters): void {
                $scope->applyTo(AttendancePopulation::filter($employees, $filters))
                    ->where('status', '!=', EmployeeStatus::Terminated);
            });

        $types = array_values(array_unique(array_map('intval', [
            ...($filters['leave_type_ids'] ?? []),
            ...(isset($filters['leave_type_id']) ? [$filters['leave_type_id']] : []),
        ])));
        if ($types !== []) {
            $query->whereIn('leave_type_id', $types);
        }

        if (array_key_exists('is_excuse', $filters)) {
            $query->excuses(filter_var($filters['is_excuse'], FILTER_VALIDATE_BOOLEAN));
        }

        $sort = (string) ($filters['sort'] ?? 'start_date');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';

        if (ltrim($sort, '-') === 'employee_name') {
            foreach (['first_name', 'middle_name', 'last_name'] as $column) {
                $query->orderBy(Employee::withTrashed()->select($column)->whereColumn('employees.id', 'leave_requests.employee_id'), $direction);
            }
        } else {
            $query->orderBy(ltrim($sort, '-'), $direction);
        }

        return $query->orderBy('id', $direction)
            ->with(LeaveRequestQuery::renderRelations())
            ->paginate($perPage);
    }
}
