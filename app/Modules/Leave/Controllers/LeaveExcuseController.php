<?php

declare(strict_types=1);

namespace App\Modules\Leave\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Requests\FileLeaveExcuseRequest;
use App\Modules\Leave\Requests\LeaveReasonRequest;
use App\Modules\Leave\Resources\LeaveRequestResource;
use App\Modules\Leave\Services\LeaveBalances;
use App\Modules\Leave\Services\LeaveExcuseService;
use App\Modules\Leave\Services\LeaveRequestQuery;
use App\Modules\Leave\Support\LeaveReach;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * HR's excuses (LEAVE_BUILD.md §6), row-scoped through the employee's
 * current unit (D6).
 */
class LeaveExcuseController extends Controller
{
    use ApiRespond;

    public function __construct(
        private readonly LeaveExcuseService $excuses,
        private readonly LeaveBalances $balances,
    ) {}

    /**
     * POST /api/leave/excuses
     */
    public function store(FileLeaveExcuseRequest $request): JsonResponse
    {
        $employee = Employee::query()->findOrFail($request->validated('employee_id'));
        LeaveReach::ensure($request->user(), $employee, 'leave.excuses.create');

        $excuse = $this->excuses->file($employee, $request->user(), $request->validated(), $request->uploads(), $request->boolean('force'));

        return $this->created($this->outcome($request, $excuse), 'تم تسجيل العذر.');
    }

    /**
     * DELETE /api/leave/excuses/{leaveRequest} — the route's `can:` has
     * checked the reach before the body is read.
     */
    public function destroy(LeaveReasonRequest $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $undone = $this->excuses->undo($leaveRequest, $request->user(), $request->validated('reason'));

        return $this->success($this->outcome($request, $undone), 'تم التراجع عن العذر.');
    }

    /**
     * @return array<string, mixed>
     */
    private function outcome(Request $request, LeaveRequest $leave): array
    {
        $leave = $leave->fresh()->load(LeaveRequestQuery::renderRelations());

        return [
            'leave_request' => (new LeaveRequestResource($leave))->resolve($request),
            'balance'       => $this->balances->for((int) $leave->employee_id, $leave->start_date->year)->toArray(),
        ];
    }
}
