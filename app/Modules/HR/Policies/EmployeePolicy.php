<?php

declare(strict_types=1);

namespace App\Modules\HR\Policies;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Requests\EmployeeRequest;
use App\Modules\HR\Resources\EmployeeResource;
use App\Modules\HR\Services\EmployeeService;
use App\Shared\Support\OutOfScope;
use App\Shared\Support\RequestRowScopes;
use App\Shared\Support\RowScope;
use Illuminate\Auth\Access\Response;

/**
 * EmployeePolicy — "may this user touch THIS employee?" (GAP-3, stage 4b).
 *
 * The verb was already decided by `permission:` on the route (stage 4a). This
 * answers the row: is the employee's unit inside the reach of a grant that
 * carries that same permission. Each ability names its permission explicitly,
 * so the scope consulted is always the one belonging to the verb being used.
 *
 * WIRED THROUGH THE GATE, so the super-admin `Gate::before` bypass applies
 * without anything here knowing about it. Registered by `#[UsePolicy]` on the
 * model — no provider edit — and invoked by `can:` route middleware, which runs
 * after route-model binding (the priority list in bootstrap/app.php).
 *
 * AN OUT-OF-SCOPE ROW IS DENIED WITH `OutOfScope::deny()`, not a bare false.
 * A false renders the generic "no permission" 403 — the same words a caller
 * missing the VERB gets — and decision 19 asks for a refusal that says the row
 * is out of reach. The denial carries that message and the renderer shows it.
 * It is still an answer, not an exception: `Gate::allows('view', $e)` returns
 * false for an out-of-scope row.
 *
 * SCOPES ARE READ THROUGH `RequestRowScopes`, so `can:` on the route, the
 * form request's field gate and the controller's warm-up share one resolved
 * scope per permission instead of walking the tree each.
 *
 * ⚙️ THE `manager` EMBED IS NOT SCOPED, on purpose (work order trap 3). A
 * section head's own manager sits one level up, outside their subtree, and
 * `EmployeeStub` still names them. That is a name and a staff number from the
 * org chart — what the person would read on the wall — and hiding who your
 * manager is would make the app's home screen useless. Only the ROW is scoped.
 */
class EmployeePolicy
{
    /**
     * The scope a listing is narrowed to. Not a Gate ability — a list is not
     * refused, it is filtered — so the controller asks for it directly and
     * hands it to the service.
     *
     * ⚙️ A FILTER OR SORT ON A PAY FIELD NARROWS IT FURTHER (2026-09-26). The
     * classification axes are gated by `hr.employees.viewSalary` on read and on
     * write, and a filter is a read: `?activity_type=1` answers "which of these
     * people are engineers" one value at a time, for every row it returns. So
     * when the request filters or sorts by a salary-gated field, the rows are
     * the ones BOTH grants reach — the view grant's and the viewSalary grant's.
     * A caller who can see pay for one section filters within that section and
     * learns nothing about anyone else. A caller who holds no viewSalary at all
     * never gets here: EmployeeIndexRequest refuses the key with a 422.
     *
     * @param  array<string, mixed>  $filters  the validated list filters
     */
    public function listScope(User $user, array $filters = [], ?string $sort = null): RowScope
    {
        $scope = RequestRowScopes::forUser($user, 'hr.employees.view');

        if (self::salaryGatedListKeys($filters, $sort) !== []) {
            $scope = $scope->intersect(RequestRowScopes::forUser($user, EmployeeResource::VIEW_SALARY));
        }

        return $scope;
    }

    /**
     * The salary-gated fields a list request uses, as a filter or as the sort
     * key. Derived from the two lists that already exist — what the service
     * can filter and sort by, and what the salary gate covers — so an axis made
     * filterable or sortable later is gated without anyone remembering to.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, string>
     */
    public static function salaryGatedListKeys(array $filters, ?string $sort): array
    {
        $used = [];

        foreach (self::salaryGatedFilters() as $key) {
            if (($filters[$key] ?? null) !== null && $filters[$key] !== '') {
                $used[] = $key;
            }
        }

        $sortKey = $sort === null ? '' : ltrim($sort, '-');

        if (in_array($sortKey, self::salaryGatedSorts(), true)) {
            $used[] = $sortKey;
        }

        return $used;
    }

    /**
     * @return array<int, string>
     */
    public static function salaryGatedFilters(): array
    {
        return array_values(array_intersect(array_keys(EmployeeService::FILTERABLE), EmployeeRequest::SALARY_GATED_FIELDS));
    }

    /**
     * @return array<int, string>
     */
    public static function salaryGatedSorts(): array
    {
        return array_values(array_intersect(array_keys(EmployeeService::SORTABLE), EmployeeRequest::SALARY_GATED_FIELDS));
    }

    public function view(User $user, Employee $employee): Response
    {
        return $this->ensure($user, $employee, 'hr.employees.view');
    }

    public function update(User $user, Employee $employee): Response
    {
        return $this->ensure($user, $employee, 'hr.employees.update');
    }

    public function delete(User $user, Employee $employee): Response
    {
        return $this->ensure($user, $employee, 'hr.employees.delete');
    }

    public function viewAssignments(User $user, Employee $employee): Response
    {
        return $this->ensure($user, $employee, 'hr.assignments.view');
    }

    public function recordAssignment(User $user, Employee $employee): Response
    {
        return $this->ensure($user, $employee, 'hr.assignments.create');
    }

    public function viewWarnings(User $user, Employee $employee): Response
    {
        return $this->ensure($user, $employee, 'hr.warnings.view');
    }

    public function createWarning(User $user, Employee $employee): Response
    {
        return $this->ensure($user, $employee, 'hr.warnings.create');
    }

    /**
     * May this user put an employee INTO this unit — on create, on a transfer
     * through update, or on a recorded posting?
     *
     * The DESTINATION must be in scope as well as the person. Checking only the
     * current row would let a manager push someone out into another division
     * (or, on create, file a new hire anywhere in the company). A null unit is
     * in scope only for a company-wide grant, by the same rule that hides
     * unit-less rows from everyone else.
     *
     * Called with the permission of the verb in use, because create, update
     * and posting are three different grants.
     */
    public function placeIn(User $user, ?int $departmentId, string $permission): Response
    {
        return RequestRowScopes::forUser($user, $permission)->covers($departmentId)
            ? Response::allow()
            : OutOfScope::deny('لا يمكنك إسناد موظف إلى وحدة تنظيمية خارج نطاق صلاحياتك.');
    }

    private function ensure(User $user, Employee $employee, string $permission): Response
    {
        return RequestRowScopes::forUser($user, $permission)->covers($employee->department_id === null ? null : (int) $employee->department_id)
            ? Response::allow()
            : OutOfScope::deny('هذا الموظف خارج نطاق صلاحياتك.');
    }
}
