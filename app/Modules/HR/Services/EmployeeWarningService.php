<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeWarning;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * EmployeeWarningService
 *
 * Business logic for الإنذارات. Warnings are scoped to one employee on every
 * path — there is no "all warnings across the company" list, deliberately: a
 * disciplinary record is read about a person, and an unscoped listing is a
 * report nobody asked for that would be read by everyone holding
 * hr.warnings.view.
 */
class EmployeeWarningService
{
    /**
     * One employee's warnings, most recent first.
     *
     * The relation already orders by `issued_on` then `id`, so the ordering ends
     * with the primary key (C29) — two warnings issued the same day are common
     * and would otherwise page non-deterministically.
     *
     * @return LengthAwarePaginator<EmployeeWarning>
     */
    public function paginateFor(Employee $employee, int $perPage = 15): LengthAwarePaginator
    {
        return $employee->warnings()->with('issuer')->paginate($perPage);
    }

    /**
     * Issue a warning against an employee.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Employee $employee, array $data): EmployeeWarning
    {
        return DB::transaction(fn (): EmployeeWarning => $employee->warnings()->create($data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(EmployeeWarning $warning, array $data): EmployeeWarning
    {
        $warning->update($data);

        return $warning->refresh();
    }

    /**
     * Soft-delete a warning.
     *
     * Soft, because "this warning was withdrawn" and "this warning never
     * happened" are different claims and an audit is entitled to tell them
     * apart. The row survives with `deleted_at` set, and
     * EmployeeWarning::sequence() counts only live rows, so withdrawing warning
     * #2 of 3 correctly renumbers the third to #2.
     */
    public function delete(EmployeeWarning $warning): void
    {
        $warning->delete();
    }
}
