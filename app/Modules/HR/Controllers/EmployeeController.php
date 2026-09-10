<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Requests\EmployeeRequest;
use App\Modules\HR\Resources\EmployeeResource;
use App\Modules\HR\Services\EmployeeService;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * EmployeeController
 *
 * Thin REST controller for the Employees resource. Receives validated
 * requests, delegates to EmployeeService, shapes output via EmployeeResource —
 * no business logic, queries, or transactions live here (§16.4).
 *
 * ENDPOINTS (apiResource, prefixed /api/hr):
 *   GET    /api/hr/employees          → index
 *   POST   /api/hr/employees          → store
 *   GET    /api/hr/employees/{id}     → show
 *   PUT    /api/hr/employees/{id}     → update
 *   DELETE /api/hr/employees/{id}     → destroy
 */
class EmployeeController extends Controller
{
    use ApiRespond;

    public function __construct(
        private readonly EmployeeService $employeeService,
    ) {}

    /**
     * The controller is the only layer that knows a query string exists (C1):
     * it resolves and clamps per_page, then hands the service an int.
     */
    public function index(Request $request): JsonResponse
    {
        $employees = $this->employeeService->paginate($this->perPage($request));

        return $this->paginated(
            data: EmployeeResource::collection($employees),
            message: 'تم جلب الموظفين.',
        );
    }

    public function store(EmployeeRequest $request): JsonResponse
    {
        $employee = $this->employeeService->create($request->validated());

        return $this->created(
            data: new EmployeeResource($employee),
            message: 'تم إنشاء الموظف.',
        );
    }

    public function show(Employee $employee): JsonResponse
    {
        return $this->success(
            data: new EmployeeResource($employee),
            message: 'تم جلب بيانات الموظف.',
        );
    }

    public function update(EmployeeRequest $request, Employee $employee): JsonResponse
    {
        $employee = $this->employeeService->update($employee, $request->validated());

        return $this->success(
            data: new EmployeeResource($employee),
            message: 'تم تحديث بيانات الموظف.',
        );
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $this->employeeService->delete($employee);

        return $this->success(message: 'تم حذف الموظف.');
    }
}
