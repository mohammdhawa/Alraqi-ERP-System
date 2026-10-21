<?php

declare(strict_types=1);

namespace App\Modules\Leave\Controllers;

use App\Modules\Approvals\Services\ApprovalService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Exceptions\LeaveException;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Requests\FileLeaveRequest;
use App\Modules\Leave\Requests\LeaveRequestsFilterRequest;
use App\Modules\Leave\Resources\LeaveRequestResource;
use App\Modules\Leave\Services\LeaveBalances;
use App\Modules\Leave\Services\LeaveRequestQuery;
use App\Modules\Leave\Services\LeaveRequestService;
use App\Shared\Support\RequestRowScopes;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * The employee's own leave (LEAVE_BUILD.md §4), routed under /api/me:
 * resolved from the caller's account, never from an id in the request.
 */
class MyLeaveController extends Controller
{
    use ApiRespond;

    /**
     * GET /api/me/leave-requests — the caller's requests and HR's excuses
     * for them, with legacy's filters.
     */
    public function index(LeaveRequestsFilterRequest $request, LeaveRequestQuery $query): JsonResponse
    {
        $employee = $this->employeeOf($request->user());
        RequestRowScopes::warm($request, ['approvals.reassign']);

        return $this->paginated(LeaveRequestResource::collection(
            $query->own((int) $employee->id, $request->validated(), $this->perPage($request)),
        ));
    }

    /**
     * POST /api/me/leave-requests/{leaveRequest}/cancel — the engine's
     * withdrawal (§9 Q1 = B): only while nobody has decided. Looked up among
     * the caller's own leave, so a colleague's id is a 404.
     */
    public function cancel(Request $request, int $leaveRequest, ApprovalService $engine): JsonResponse
    {
        $employee = $this->employeeOf($request->user());
        $leave = LeaveRequest::query()->where('employee_id', $employee->id)->findOrFail($leaveRequest);
        LeaveException::unlessOnDuty($employee);

        if ($leave->isExcuse() || $leave->approval === null) {
            throw new LeaveException('لا يمكن سحب عذر سجّلته الموارد البشرية.');
        }

        $engine->withdraw($leave->approval, $request->user());

        return $this->success($this->render($request, $leave), 'تم سحب طلب الإجازة.');
    }

    /**
     * POST /api/me/leave-requests
     */
    public function store(FileLeaveRequest $request, LeaveRequestService $leave, LeaveBalances $balances): JsonResponse
    {
        $employee = $this->employeeOf($request->user());
        $filed = $leave->file($employee, $request->user(), $request->validated(), $request->uploads());

        return $this->created([
            'leave_request' => $this->render($request, $filed),
            'balance'       => $balances->for((int) $employee->id, $filed->start_date->year)->toArray(),
        ], 'تم تقديم طلب الإجازة.');
    }

    private function employeeOf(User $user): Employee
    {
        return ($user->employee_id === null ? null : Employee::query()->find($user->employee_id))
            ?? throw new LeaveException('لا يوجد موظف مرتبط بهذا الحساب.', Response::HTTP_FORBIDDEN);
    }

    /**
     * @return array<string, mixed>
     */
    private function render(Request $request, LeaveRequest $leave): array
    {
        RequestRowScopes::warm($request, ['approvals.reassign']);

        return (new LeaveRequestResource($leave->fresh()->load(LeaveRequestQuery::renderRelations())))->resolve($request);
    }
}
