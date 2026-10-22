<?php

declare(strict_types=1);

namespace App\Modules\Departments\Controllers;

use App\Modules\Departments\Models\Department;
use App\Modules\Departments\Requests\StoreDepartmentRequest;
use App\Modules\Departments\Requests\UpdateDepartmentRequest;
use App\Modules\Departments\Resources\DepartmentResource;
use App\Modules\Departments\Services\DepartmentService;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * DepartmentController
 *
 * Thin REST controller for the Departments resource. Receives validated
 * requests, delegates every operation to DepartmentService, and shapes the
 * output through DepartmentResource — no business logic, queries, or
 * transactions live here (architecture report §16.4).
 *
 * ENDPOINTS (apiResource, prefixed /api/departments):
 *   GET    /api/departments          → index
 *   POST   /api/departments          → store
 *   GET    /api/departments/{id}     → show
 *   PUT    /api/departments/{id}     → update
 *   DELETE /api/departments/{id}     → destroy
 */
class DepartmentController extends Controller
{
    use ApiRespond;

    public function __construct(
        private readonly DepartmentService $departmentService,
    ) {}

    /**
     * The controller is the only layer that knows a query string exists (C1):
     * it resolves and clamps per_page, then hands the service an int.
     */
    public function index(Request $request): JsonResponse
    {
        $departments = $this->departmentService->paginate($this->perPage($request));

        return $this->paginated(
            data: DepartmentResource::collection($departments),
            message: 'تم جلب الوحدات التنظيمية.',
        );
    }

    /**
     * `load('manager')` for the same reason show() loads ancestors: the
     * Resource embeds the managing employee, and a freshly created row has no
     * relation loaded. Without it the embed would be resolved from inside the
     * Resource — or, worse, silently come back null on a unit that has a
     * manager.
     */
    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $department = $this->departmentService->create($request->validated());

        return $this->created(
            data: new DepartmentResource($department->load('manager')),
            message: 'تم إنشاء الوحدة التنظيمية.',
        );
    }

    /**
     * loadAncestors() is not optional decoration: DepartmentResource resolves
     * `sector` by walking up the tree, so without it the Resource performs that
     * walk itself — a query per tier, from the layer whose contract forbids
     * queries. The list endpoint got this via the service's withAncestors();
     * a bound model arrives with nothing loaded.
     *
     * `manager` is the second relation the Resource reads, and it is owed here
     * by the same rule.
     */
    public function show(Department $department): JsonResponse
    {
        return $this->success(
            data: new DepartmentResource($department->loadAncestors()->load('manager')),
            message: 'تم جلب الوحدة التنظيمية.',
        );
    }

    /**
     * load(), not loadMissing(): an update may have REASSIGNED manager_id, and
     * loadMissing would keep the stale employee the model was holding.
     */
    public function update(UpdateDepartmentRequest $request, Department $department): JsonResponse
    {
        $department = $this->departmentService->update($department, $request->validated());

        return $this->success(
            data: new DepartmentResource($department->load('manager')),
            message: 'تم تحديث الوحدة التنظيمية.',
        );
    }

    public function destroy(Department $department): JsonResponse
    {
        $this->departmentService->delete($department);

        return $this->success(message: 'تم حذف الوحدة التنظيمية.');
    }
}
