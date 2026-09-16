<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeWarning;
use App\Modules\HR\Requests\EmployeeWarningRequest;
use App\Modules\HR\Resources\EmployeeWarningResource;
use App\Modules\HR\Services\EmployeeWarningService;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * EmployeeWarningController
 *
 * الإنذارات — an employee's disciplinary record.
 *
 * ENDPOINTS (module prefix /api/hr):
 *   GET    /api/hr/employees/{employee}/warnings  index
 *   POST   /api/hr/employees/{employee}/warnings  store
 *   PUT    /api/hr/warnings/{warning}             update
 *   DELETE /api/hr/warnings/{warning}             destroy
 *
 * WHY READS AND CREATES ARE NESTED AND EDITS ARE NOT: a warning is always read
 * and issued in the context of a person, so those two carry the employee in the
 * path. An amendment addresses one existing row whose employee is already fixed
 * by its own FK, and nesting it would invite a URL whose two ids disagree —
 * `/employees/7/warnings/12` where warning 12 belongs to employee 9 — which is
 * a mismatch the route would have to detect and reject.
 *
 * UNLIKE assignments, warnings DO have update and delete. The two records are
 * not the same kind of thing: a posting is a historical fact that payroll
 * computes against, while a warning is a document that can be withdrawn or
 * corrected before it is served. Deletion is soft, so a withdrawn warning stays
 * visible to an audit — "withdrawn" and "never happened" are different claims.
 */
class EmployeeWarningController extends Controller
{
    use ApiRespond;

    public function __construct(
        private readonly EmployeeWarningService $warningService,
    ) {}

    public function index(Request $request, Employee $employee): JsonResponse
    {
        $warnings = $this->warningService->paginateFor($employee, $this->perPage($request));

        return $this->paginated(
            data: EmployeeWarningResource::collection($warnings),
            message: 'تم جلب الإنذارات.',
        );
    }

    public function store(EmployeeWarningRequest $request, Employee $employee): JsonResponse
    {
        $warning = $this->warningService->create($employee, $request->validated());

        return $this->created(
            data: new EmployeeWarningResource($warning->load('issuer')),
            message: 'تم تسجيل الإنذار.',
        );
    }

    public function update(EmployeeWarningRequest $request, EmployeeWarning $warning): JsonResponse
    {
        $warning = $this->warningService->update($warning, $request->validated());

        return $this->success(
            data: new EmployeeWarningResource($warning->load('issuer')),
            message: 'تم تحديث الإنذار.',
        );
    }

    public function destroy(EmployeeWarning $warning): JsonResponse
    {
        $this->warningService->delete($warning);

        return $this->success(message: 'تم حذف الإنذار.');
    }
}
