<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * EmployeeService
 *
 * Business logic for the Employees resource. Controllers delegate here and
 * never touch the model directly (§16.4–16.5). Audit logging is automatic via
 * the Employee model's HasAuditLog trait.
 */
class EmployeeService
{
    /**
     * Paginated list of employees, newest first.
     *
     * Eager-loads the department to avoid N+1 when the resource renders it.
     *
     * @return LengthAwarePaginator<Employee>
     */
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return Employee::query()
            ->with('department')
            ->latest()
            ->paginate($perPage);
    }

    /**
     * All employees (unpaginated).
     *
     * @return Collection<int, Employee>
     */
    public function all(): Collection
    {
        return Employee::query()->with('department')->latest()->get();
    }

    /**
     * Create an employee from validated attributes.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Employee
    {
        return Employee::create($data);
    }

    /**
     * Update an existing employee with validated attributes.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Employee $employee, array $data): Employee
    {
        $employee->update($data);

        return $employee->refresh();
    }

    /**
     * Delete an employee.
     */
    public function delete(Employee $employee): void
    {
        $employee->delete();
    }
}
