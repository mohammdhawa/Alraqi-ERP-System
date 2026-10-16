<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Departments\Models\Department;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use App\Shared\Support\RequestRowScopes;
use App\Shared\Support\SearchTerm;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whose attendance a list or report is about — legacy's applyUserFilters(),
 * on ERP employees.
 *
 * - REACHABLE: the employees the caller's attendance.records.view grants
 *   reach (GAP-3, through the CURRENT unit — D6), trashed ones included,
 *   because a departed person's records are still history.
 * - EXPECTED: those of them expected to attend (Q7) — active employees with
 *   an active account. Legacy's `attendance_check` flag is gone. This is who
 *   has a row in the monthly report and who can be absent today.
 *
 * Both take the same person filters, so every list and report narrows by the
 * same rules: employees by id, units by id — each WITH its subtree, as
 * legacy's department filter covered its sections — and a name part or
 * staff number. A filter can only narrow the reach, never widen it.
 */
final class AttendancePopulation
{
    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Employee>
     */
    public function reachable(User $user, array $filters): Builder
    {
        $query = RequestRowScopes::forUser($user, 'attendance.records.view')->applyTo(Employee::withTrashed());

        return self::filter($query, $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Employee>
     */
    public function expected(User $user, array $filters): Builder
    {
        return RequestRowScopes::forUser($user, 'attendance.records.view')
            ->applyTo(self::filter(Employee::query(), $filters))
            ->where('status', EmployeeStatus::Active)
            ->whereHas('user', fn (Builder $account) => $account->where('is_active', true));
    }

    /**
     * @template TBuilder of Builder<Employee>
     *
     * @param  TBuilder  $employees
     * @param  array<string, mixed>  $filters
     * @return TBuilder
     */
    public static function filter(Builder $employees, array $filters): Builder
    {
        $ids = self::ids($filters, 'employee_id', 'employee_ids');
        if ($ids !== []) {
            $employees->whereIn($employees->qualifyColumn('id'), $ids);
        }

        $units = self::ids($filters, 'department_id', 'department_ids');
        if ($units !== []) {
            $employees->whereIn($employees->qualifyColumn('department_id'), Department::subtreeIds($units));
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $employees->where(function (Builder $inner) use ($search): void {
                foreach (['first_name', 'middle_name', 'last_name', 'employee_number'] as $column) {
                    SearchTerm::orWhere($inner, $column, $search);
                }
            });
        }

        return $employees;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, int>
     */
    private static function ids(array $filters, string $one, string $many): array
    {
        return array_values(array_unique(array_map('intval', [
            ...($filters[$many] ?? []),
            ...(isset($filters[$one]) ? [$filters[$one]] : []),
        ])));
    }
}
