<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Controllers;

use App\Modules\Attendance\Models\EmployeeWorkSiteAssignment;
use App\Modules\Attendance\Requests\EndWorkSiteAssignmentRequest;
use App\Modules\Attendance\Requests\StoreWorkSiteAssignmentRequest;
use App\Modules\Attendance\Resources\WorkSiteAssignmentResource;
use App\Modules\Attendance\Services\WorkSiteAssignmentService;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class WorkSiteAssignmentController extends Controller
{
    use ApiRespond;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'employee_id' => ['sometimes', 'integer', 'min:1'],
            'work_site_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = EmployeeWorkSiteAssignment::query()->with(['employee', 'site']);
        if (isset($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }
        if (isset($filters['work_site_id'])) {
            $query->where('work_site_id', $filters['work_site_id']);
        }

        return $this->paginated(WorkSiteAssignmentResource::collection(
            $query->orderByDesc('effective_from')->orderByDesc('id')->paginate($this->perPage($request)),
        ));
    }

    public function store(StoreWorkSiteAssignmentRequest $request, WorkSiteAssignmentService $sites): JsonResponse
    {
        return $this->created(new WorkSiteAssignmentResource($sites->assign($request->validated())));
    }

    public function update(EndWorkSiteAssignmentRequest $request, EmployeeWorkSiteAssignment $assignment, WorkSiteAssignmentService $sites): JsonResponse
    {
        return $this->success(new WorkSiteAssignmentResource(
            $sites->endAssignment($assignment, $request->validated('effective_to')),
        ));
    }
}
