<?php

declare(strict_types=1);

namespace App\Modules\Departments\Controllers;

use App\Modules\Departments\Models\Department;
use App\Modules\Departments\Requests\DepartmentRequest;
use App\Modules\Departments\Resources\DepartmentResource;
use App\Modules\Departments\Services\DepartmentService;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
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

    public function index(): JsonResponse
    {
        $departments = $this->departmentService->paginate();

        return $this->success(
            data: DepartmentResource::collection($departments),
            message: 'Departments retrieved.',
        );
    }

    public function store(DepartmentRequest $request): JsonResponse
    {
        $department = $this->departmentService->create($request->validated());

        return $this->created(
            data: new DepartmentResource($department),
            message: 'Department created.',
        );
    }

    public function show(Department $department): JsonResponse
    {
        return $this->success(
            data: new DepartmentResource($department),
            message: 'Department retrieved.',
        );
    }

    public function update(DepartmentRequest $request, Department $department): JsonResponse
    {
        $department = $this->departmentService->update($department, $request->validated());

        return $this->success(
            data: new DepartmentResource($department),
            message: 'Department updated.',
        );
    }

    public function destroy(Department $department): JsonResponse
    {
        $this->departmentService->delete($department);

        return $this->success(message: 'Department deleted.');
    }
}
