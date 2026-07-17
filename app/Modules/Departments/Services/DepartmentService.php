<?php

declare(strict_types=1);

namespace App\Modules\Departments\Services;

use App\Modules\Departments\Models\Department;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
 *
 * CONCURRENCY: the hierarchy invariants (singleton root; a childless parent
 * before soft-delete) are checked-then-written by the DepartmentHierarchyGuard
 * in the model. Between the check and the write two concurrent requests can
 * race, so this service is the transaction + locking boundary that closes the
 * window:
 *   - Root creation has no row to lock (the root does not exist yet), so it is
 *     serialized on a NAMED application lock; the second contender then sees the
 *     committed root and the guard rejects it.
 *   - Child creation and deletion take a row lock on the PARENT inside a
 *     transaction, so "create a child under X" and "delete X" cannot interleave.
 *
 * NOTE: this is enforced for real only on a driver with true row locks / a
 * shared lock store (MySQL + database/redis cache). The sqlite :memory: test
 * driver treats lockForUpdate as a no-op and the cache lock as process-local, so
 * the guarantee is correctness-by-construction there rather than test-observable.
 */
class DepartmentService
{
    /**
     * Seconds to hold the root-creation lock, and how long to wait for it.
     */
    private const ROOT_LOCK_SECONDS = 10;

    private const ROOT_LOCK_WAIT_SECONDS = 5;

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
     * A root create (no parent) is serialized on a named lock that wraps the
     * whole transaction, so a second concurrent root create waits, then sees the
     * committed root and is rejected by the singleton-root guard. A child create
     * locks its parent row for the duration of the transaction, so the parent
     * cannot be soft-deleted out from under it mid-request.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Department
    {
        $parentId = $data['parent_id'] ?? null;

        if ($parentId === null) {
            return Cache::lock('departments:root-create', self::ROOT_LOCK_SECONDS)
                ->block(self::ROOT_LOCK_WAIT_SECONDS, fn (): Department => DB::transaction(
                    fn (): Department => Department::create($data),
                ));
        }

        return DB::transaction(function () use ($data, $parentId): Department {
            // Lock the parent row so a concurrent delete of it blocks until this
            // child is committed (the parent then has a child and cannot be
            // soft-deleted).
            Department::whereKey($parentId)->lockForUpdate()->first();

            return Department::create($data);
        });
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
     *
     * Wrapped in a transaction that locks the row, so a concurrent "create a
     * child under this unit" (which locks the same row) cannot slip a new child
     * in between the model's no-children check and the soft-delete. The model's
     * deleting hook still owns the actual rules (root undeletable, no live
     * children); this only makes them race-safe.
     */
    public function delete(Department $department): void
    {
        DB::transaction(function () use ($department): void {
            Department::whereKey($department->getKey())->lockForUpdate()->first();

            $department->delete();
        });
    }
}
