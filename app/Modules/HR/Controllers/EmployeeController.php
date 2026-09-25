<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Policies\EmployeePolicy;
use App\Modules\HR\Requests\EmployeeIndexRequest;
use App\Modules\HR\Requests\EmployeeRequest;
use App\Modules\HR\Resources\EmployeeResource;
use App\Modules\HR\Services\EmployeeService;
use App\Shared\Support\RequestRowScopes;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * EmployeeController
 *
 * Thin REST controller for the Employees resource: validated request in, one
 * service call, one Resource, one envelope. No queries, transactions or
 * business branching live here (C2, C3).
 *
 * Every action that renders EmployeeResource first warms its field scopes
 * (`RequestRowScopes::warm`) — since GAP-3 the pay and identity gates are per
 * row, and warming resolves them here so the Resource only reads a memo. It is
 * the same debt as eager-loading: whoever picks the Resource owes it.
 *
 * ⚠️ WARM `request()`, NOT THE FORMREQUEST. A FormRequest is a COPY of the
 * base request with its own attribute bag, and a Resource is rendered against
 * the base one — warming the FormRequest leaves the memo where the Resource
 * never looks, and every row's gate resolves the scope again.
 * `EmployeeScopedFieldsTest` counts the walks and fails if that happens.
 *
 * ENDPOINTS (module prefix /api/hr):
 *   GET    /api/hr/employees            index
 *   POST   /api/hr/employees            store
 *   GET    /api/hr/employees/{employee} show
 *   PUT    /api/hr/employees/{employee} update
 *   DELETE /api/hr/employees/{employee} destroy
 */
class EmployeeController extends Controller
{
    use ApiRespond;

    public function __construct(
        private readonly EmployeeService $employeeService,
        private readonly EmployeePolicy $policy,
    ) {}

    /**
     * The controller is the only layer that knows a query string exists (C1):
     * it resolves and clamps `per_page` and hands the service an int plus a
     * validated filter array. The service stays callable from a console command
     * with no HTTP present.
     *
     * ROW SCOPE (GAP-3): a list is FILTERED, not refused — a division head
     * sees their subtree, and `meta.total` counts only that. The decision is
     * the policy's; this action only carries the answer to the service, which
     * takes it as a required argument so no caller can forget it.
     */
    public function index(EmployeeIndexRequest $request): JsonResponse
    {
        RequestRowScopes::warm(request(), EmployeeResource::FIELD_PERMISSIONS);

        $employees = $this->employeeService->paginate(
            scope: $this->policy->listScope($request->user()),
            perPage: $this->perPage($request),
            filters: $request->validated(),
            sort: $request->validated('sort'),
        );

        return $this->paginated(
            data: EmployeeResource::collection($employees),
            message: 'تم جلب الموظفين.',
        );
    }

    public function store(EmployeeRequest $request): JsonResponse
    {
        $employee = $this->employeeService->create($request->validated());

        RequestRowScopes::warm(request(), EmployeeResource::FIELD_PERMISSIONS);

        return $this->created(
            data: new EmployeeResource($this->employeeService->prepareForResource($employee)),
            message: 'تم إنشاء الموظف.',
        );
    }

    /**
     * Eager-loading a model the controller already holds is permitted and is
     * required here: EmployeeResource renders the department, title and direct
     * manager objects, and a Resource that dereferences an unloaded relation
     * breaks its own contract. Whoever picks the Resource owes it the relations
     * it reads.
     */
    public function show(Employee $employee): JsonResponse
    {
        RequestRowScopes::warm(request(), EmployeeResource::FIELD_PERMISSIONS);

        return $this->success(
            data: new EmployeeResource($this->employeeService->prepareForResource($employee)),
            message: 'تم جلب بيانات الموظف.',
        );
    }

    /**
     * The service reloads the relations and re-resolves the manager, because an
     * update may have moved the employee to another unit — and the manager is
     * read from the unit, so a stale relation would name the manager of the
     * department they just left. See EmployeeService::prepareForResource().
     */
    public function update(EmployeeRequest $request, Employee $employee): JsonResponse
    {
        $employee = $this->employeeService->update($employee, $request->validated());

        RequestRowScopes::warm(request(), EmployeeResource::FIELD_PERMISSIONS);

        return $this->success(
            data: new EmployeeResource($this->employeeService->prepareForResource($employee)),
            message: 'تم تحديث بيانات الموظف.',
        );
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $this->employeeService->delete($employee);

        // 200 with a message, never 204 — Symfony strips a 204 body and the
        // Arabic message would never reach the client (C19).
        return $this->success(message: 'تم حذف الموظف.');
    }
}
