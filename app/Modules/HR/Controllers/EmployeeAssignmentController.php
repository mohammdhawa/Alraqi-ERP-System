<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Requests\EmployeeAssignmentRequest;
use App\Modules\HR\Resources\EmployeeAssignmentResource;
use App\Modules\HR\Services\EmployeeAssignmentService;
use App\Modules\HR\Services\EmployeeService;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * EmployeeAssignmentController
 *
 * سجل التعيين — one employee's posting history.
 *
 * ENDPOINTS (module prefix /api/hr):
 *   GET  /api/hr/employees/{employee}/assignments  index
 *   POST /api/hr/employees/{employee}/assignments  store
 *
 * THERE IS NO update AND NO destroy, and no permission exists that could reach
 * one. The history is append-only: a posting recorded wrongly is corrected by
 * recording the correction, the way a ledger is. Editing last year's posting
 * would change what a payslip was computed against, after the fact and without
 * a trace of the original.
 *
 * NESTED UNDER THE EMPLOYEE because a posting has no meaning apart from the
 * person — there is no "all assignments" listing to page through, and scoping
 * it in the URL means the row-level question ("whose history is this?") is
 * answered by route-model binding rather than by a filter a caller could omit.
 */
class EmployeeAssignmentController extends Controller
{
    use ApiRespond;

    public function __construct(
        private readonly EmployeeService $employeeService,
        private readonly EmployeeAssignmentService $assignmentService,
    ) {}

    public function index(Request $request, Employee $employee): JsonResponse
    {
        $assignments = $this->employeeService->paginateAssignments(
            $employee,
            $this->perPage($request),
        );

        return $this->paginated(
            data: EmployeeAssignmentResource::collection($assignments),
            message: 'تم جلب سجل التعيين.',
        );
    }

    public function store(EmployeeAssignmentRequest $request, Employee $employee): JsonResponse
    {
        $assignment = $this->assignmentService->record($employee, $request->validated());

        return $this->created(
            data: new EmployeeAssignmentResource($assignment->load(['department', 'jobTitle'])),
            message: 'تم تسجيل التعيين.',
        );
    }
}
