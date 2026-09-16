<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeAssignment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * EmployeeService
 *
 * Business logic for the Employees resource. Controllers delegate here and
 * never touch the model directly (C3). Audit logging is automatic via the
 * Employee model's HasAuditLog trait, and the posting history is maintained by
 * the model's own hooks — so this service does NOT write assignments, which is
 * deliberate: if it did, a write from the importer or tinker would produce no
 * history and the two paths would disagree (P2).
 *
 * WHAT THE TRANSACTIONS ARE FOR: a single `Employee::create()` is atomic on its
 * own, but its `saved` hook goes on to open a posting row, and an update can
 * close one posting and open another. Those are two or three statements that
 * must not half-apply — an employee whose transfer closed the old posting and
 * then failed to open the new one has no current unit at all.
 */
class EmployeeService
{
    /**
     * Columns a client may sort by, mapped from the public sort key to the
     * real column (C30).
     *
     * ⚠️ ALLOW-LISTED, NOT INTERPOLATED. Passing a query parameter into
     * `orderBy()` is SQL injection through the back door, and it also publishes
     * internal column names as API surface — renaming a column would then break
     * clients. The map is the contract: the left side is public and stable, the
     * right side is ours to change.
     *
     * `full_name` is absent on purpose — it is an accessor, not a column, so
     * there is nothing for SQL to sort by. Sorting by name means sorting by the
     * parts, which `last_name` does.
     *
     * @var array<string, string>
     */
    public const SORTABLE = [
        'employee_number' => 'employee_number',
        'first_name'      => 'first_name',
        'last_name'       => 'last_name',
        'hire_date'       => 'hire_date',
        'created_at'      => 'created_at',
    ];

    /**
     * Filters a client may apply, with the column each one matches exactly.
     *
     * @var array<string, string>
     */
    public const FILTERABLE = [
        'status'        => 'status',
        'department_id' => 'department_id',
        'job_title_id'  => 'job_title_id',
        'activity_type' => 'activity_type',
    ];

    /**
     * Paginated list of employees.
     *
     * Eager-loads `department`, `jobTitle` and `directManager` because
     * EmployeeResource renders all three, and a Resource that dereferences an
     * unloaded relation is an N+1 per row — whoever chooses the Resource owes
     * it its relations.
     *
     * `directManager` costs one extra query for the whole page and fires for
     * almost nobody: the column is null for everyone whose manager is their
     * unit head, and is set only for a reporting line that runs outside the
     * tree.
     *
     * ORDERING IS DETERMINISTIC (C29): every branch ends with the primary key.
     * This is not pedantry — rows created in one import share a single
     * `created_at`, and two employees named محمد أحمد is ordinary rather than
     * exotic, so both the default sort and a name sort are fully tied without
     * the tie-break. When the sort key carries no ordering information, nothing
     * requires page 1 and page 2 to agree on which rows fall where: a row can
     * appear twice while another is never returned at all.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Employee>
     */
    public function paginate(int $perPage = 15, array $filters = [], ?string $sort = null): LengthAwarePaginator
    {
        return $this->query($filters, $sort)->paginate($perPage);
    }

    /**
     * All employees (unpaginated). For select inputs only — deliberately NOT
     * wired to a route, and it must stay that way or gain a hard cap (C27).
     *
     * @return Collection<int, Employee>
     */
    public function all(): Collection
    {
        return $this->query()->get();
    }

    /**
     * The posting history of one employee, newest first.
     *
     * Lives here rather than on an assignments service because it is a read of
     * one employee's own record; the assignments service owns WRITING history.
     *
     * @return LengthAwarePaginator<EmployeeAssignment>
     */
    public function paginateAssignments(Employee $employee, int $perPage = 15): LengthAwarePaginator
    {
        return $employee->assignments()
            ->with(['department', 'jobTitle'])
            ->paginate($perPage);
    }

    /**
     * Create an employee from validated attributes.
     *
     * The opening posting row is written by the model's `saved` hook, not here
     * — see the class docblock. The transaction is what makes the pair atomic.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Employee
    {
        return DB::transaction(fn (): Employee => Employee::create($data));
    }

    /**
     * Update an employee with validated attributes.
     *
     * A change to the department, title or grade closes the current posting and
     * opens a new one — again in the model hook, so the same thing happens when
     * the importer writes. The transaction covers the close and the open
     * together: half a transfer leaves an employee with no current unit.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Employee $employee, array $data): Employee
    {
        return DB::transaction(function () use ($employee, $data): Employee {
            $employee->update($data);

            return $employee->refresh();
        });
    }

    /**
     * Soft-delete an employee.
     *
     * Never a hard delete: users, departments (`manager_id`), postings,
     * warnings and every future module hold references, and the person's
     * identity must stay resolvable after they leave.
     */
    public function delete(Employee $employee): void
    {
        $employee->delete();
    }

    /**
     * The shared base query: eager loads, filters, ordering.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Employee>
     */
    private function query(array $filters = [], ?string $sort = null): Builder
    {
        $query = Employee::query()->with(['department', 'jobTitle', 'directManager']);

        foreach (self::FILTERABLE as $key => $column) {
            if (($filters[$key] ?? null) !== null && $filters[$key] !== '') {
                $query->where($column, $filters[$key]);
            }
        }

        if (($filters['search'] ?? null) !== null && $filters['search'] !== '') {
            $this->applySearch($query, (string) $filters['search']);
        }

        return $this->applySort($query, $sort);
    }

    /**
     * Free-text search across the name parts and both staff numbers.
     *
     * WHY THE NAME PARTS AND NOT `full_name`: full_name is an accessor computed
     * in PHP, so there is no column to match against. Searching each part is
     * what makes "أحمد" find someone whose father's name is أحمد — which is how
     * people are actually looked up here, since the middle name is the father's
     * and is spoken as part of the name.
     *
     * Both numbers are searched because HR will hold paper carrying the old
     * M024333 for years after the import, and being unable to find someone by
     * the number printed on their file would make `legacy_number` pointless.
     *
     * The whole clause is grouped, so it cannot escape an active filter and
     * turn `status=active&search=X` into "active employees OR anyone called X".
     */
    private function applySearch(Builder $query, string $term): void
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $query->where(function (Builder $inner) use ($like): void {
            foreach (['first_name', 'middle_name', 'last_name', 'employee_number', 'legacy_number'] as $column) {
                $inner->orWhere($column, 'like', $like);
            }
        });
    }

    /**
     * Apply an allow-listed sort, defaulting to newest first.
     *
     * A leading `-` means descending. An unknown key never reaches here — the
     * form request rejects it with a 422 rather than falling back silently
     * (P3), and this method reads the same allow-list, so the two cannot
     * disagree about what is sortable.
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    private function applySort(Builder $query, ?string $sort): Builder
    {
        if ($sort === null || $sort === '') {
            return $query->latest()->orderByDesc('id');
        }

        $descending = str_starts_with($sort, '-');
        $key        = ltrim($sort, '-');
        $column     = self::SORTABLE[$key] ?? null;

        if ($column === null) {
            return $query->latest()->orderByDesc('id');
        }

        return $query->orderBy($column, $descending ? 'desc' : 'asc')->orderByDesc('id');
    }
}
