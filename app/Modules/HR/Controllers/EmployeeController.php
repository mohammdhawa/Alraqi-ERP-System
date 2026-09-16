<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Requests\EmployeeIndexRequest;
use App\Modules\HR\Requests\EmployeeRequest;
use App\Modules\HR\Resources\EmployeeResource;
use App\Modules\HR\Services\EmployeeService;
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
    ) {}

    /**
     * The controller is the only layer that knows a query string exists (C1):
     * it resolves and clamps `per_page` and hands the service an int plus a
     * validated filter array. The service stays callable from a console command
     * with no HTTP present.
     */
    public function index(EmployeeIndexRequest $request): JsonResponse
    {
        $employees = $this->employeeService->paginate(
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

        return $this->created(
            data: new EmployeeResource($employee->load(['department', 'jobTitle', 'directManager'])),
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
        return $this->success(
            data: new EmployeeResource($employee->load(['department', 'jobTitle', 'directManager'])),
            message: 'تم جلب بيانات الموظف.',
        );
    }

    /**
     * load(), not loadMissing(): an update may have changed direct_manager_id
     * (or the department, or the title), and loadMissing would render the
     * relation the model was holding before the write.
     */
    public function update(EmployeeRequest $request, Employee $employee): JsonResponse
    {
        $employee = $this->employeeService->update($employee, $request->validated());

        return $this->success(
            data: new EmployeeResource($employee->load(['department', 'jobTitle', 'directManager'])),
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
