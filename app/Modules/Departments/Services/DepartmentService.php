<?php

declare(strict_types=1);

namespace App\Modules\Departments\Services;

use App\Modules\Departments\Models\Department;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * DepartmentService
 *
 * Holds the business logic for the Departments resource. Controllers call
 * into this service and never touch the model directly (architecture report
 * §16.4–16.5). Keeping CRUD here means the same logic is reusable from console
 * commands, jobs, or other modules, and is unit-testable without HTTP.
 *
 * Audit logging is automatic: the Department model uses HasAuditLog, so
 * create/update/delete events are recorded without explicit calls here.
 */
class DepartmentService
{
    /**
     * Paginated list of departments, newest first.
     *
     * @return LengthAwarePaginator<Department>
     */
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return Department::query()
            ->latest()
            ->paginate($perPage);
    }

    /**
     * All departments (unpaginated). Useful for select inputs.
     *
     * @return Collection<int, Department>
     */
    public function all(): Collection
    {
        return Department::query()->latest()->get();
    }

    /**
     * Create a department from validated attributes.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Department
    {
        return Department::create($data);
    }

    /**
     * Update an existing department with validated attributes.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Department $department, array $data): Department
    {
        $department->update($data);

        return $department->refresh();
    }

    /**
     * Delete a department.
     */
    public function delete(Department $department): void
    {
        $department->delete();
    }
}
