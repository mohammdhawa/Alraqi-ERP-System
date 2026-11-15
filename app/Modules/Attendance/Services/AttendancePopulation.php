<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use App\Shared\Support\RequestRowScopes;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whose attendance a list or report is about — legacy's applyUserFilters(),
 * on ERP employees.
 *
 * - REACHABLE: the employees the caller's attendance.records.view grants
 *   reach (GAP-3, through the CURRENT unit — D6), trashed ones included,
 *   because a departed person's records are still history — the records
 *   list.
 * - ON THE ROLL: of them, those not departed (soft-deleted or terminated) —
 *   the daily board (parity audit C-8).
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
     * Those still on the roll: in reach, not departed — neither soft-deleted
     * nor terminated. Who the daily board counts (owner, 2026-10-20, parity
     * audit C-8; legacy's board left deleted users out). A suspended
     * employee is still on the roll.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Employee>
     */
    public function onRoll(User $user, array $filters): Builder
    {
        return RequestRowScopes::forUser($user, 'attendance.records.view')
            ->applyTo(self::filter(Employee::query(), $filters))
            ->where('status', '!=', EmployeeStatus::Terminated);
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
        return $employees->matchingPeople($filters);
    }
}
