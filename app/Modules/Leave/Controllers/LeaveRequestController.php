<?php

declare(strict_types=1);

namespace App\Modules\Leave\Controllers;

use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Requests\LeaveRegisterRequest;
use App\Modules\Leave\Requests\OnLeaveRequest;
use App\Modules\Leave\Requests\ReturnToWorkRequest;
use App\Modules\Leave\Resources\LeaveRegisterRowResource;
use App\Modules\Leave\Resources\LeaveRequestResource;
use App\Modules\Leave\Services\LeaveBalances;
use App\Modules\Leave\Services\LeaveRegister;
use App\Modules\Leave\Services\LeaveRequestQuery;
use App\Modules\Leave\Services\OnLeaveRoster;
use App\Modules\Leave\Services\ReturnToWorkService;
use App\Shared\Models\Attachment;
use App\Shared\Services\AttachmentStore;
use App\Shared\Support\RequestRowScopes;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One leave, read by whoever LeaveRequestPolicy admits — the request screen
 * an approver opens from the inbox (LEAVE_BUILD.md §4). The route's `can:`
 * runs before anything here.
 */
class LeaveRequestController extends Controller
{
    use ApiRespond;

    /**
     * GET /api/leave/requests — HR's register.
     */
    public function index(LeaveRegisterRequest $request, LeaveRegister $register): JsonResponse
    {
        $filters = $request->filters();
        $page = $register->page($request->user(), $filters, $this->perPage($request));
        RequestRowScopes::warm($request, ['approvals.reassign']);

        return $this->paginated(LeaveRegisterRowResource::collection($page['rows']), extra: [
            'from'    => $filters['from'],
            'to'      => $filters['to'],
            'summary' => $page['summary'],
        ]);
    }

    /**
     * GET /api/leave/on-leave — who is on approved leave on one day (legacy's
     * /approved-leave-users; parity audit X2). `date` defaults to today in
     * the attendance timezone.
     */
    public function onLeave(OnLeaveRequest $request, OnLeaveRoster $roster, AttendanceCalendar $calendar): JsonResponse
    {
        $filters = $request->validated();
        $filters['date'] ??= $calendar->today();
        RequestRowScopes::warm($request, ['approvals.reassign']);

        return $this->paginated(
            LeaveRequestResource::collection($roster->page($request->user(), $filters, $this->perPage($request))),
            extra: [
                'date'        => $filters['date'],
                'working_day' => $calendar->isWorkingDay(Carbon::parse($filters['date'])),
            ],
        );
    }

    /**
     * GET /api/leave/requests/{leaveRequest}
     */
    public function show(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        RequestRowScopes::warm($request, ['approvals.reassign']);

        return $this->success(new LeaveRequestResource($leaveRequest->load(LeaveRequestQuery::renderRelations())));
    }

    /**
     * POST /api/leave/requests/{leaveRequest}/return-to-work
     */
    public function returnToWork(ReturnToWorkRequest $request, LeaveRequest $leaveRequest, ReturnToWorkService $returns, LeaveBalances $balances): JsonResponse
    {
        $outcome = $returns->record($leaveRequest, $request->user(), $request->validated('returned_on'), $request->validated('reason'));
        $leave = $outcome->leave->fresh()->load(LeaveRequestQuery::renderRelations());
        RequestRowScopes::warm($request, ['approvals.reassign']);

        return $this->success([
            'leave_request' => (new LeaveRequestResource($leave))->resolve($request),
            'cancelled'     => $outcome->cancelled,
            'days_returned' => $outcome->daysReturned,
            'balance'       => $balances->for((int) $leave->employee_id, $leave->start_date->year)->toArray(),
        ], $outcome->cancelled ? 'أُلغيت الإجازة لعودة الموظف في يومها الأول.' : 'تم تقصير الإجازة إلى الأيام المأخوذة فعلاً.');
    }

    /**
     * GET /api/leave/requests/{leaveRequest}/attachments/{attachment} —
     * streamed, never a URL. The attachment is bound scoped to the leave: a
     * file of another request is a 404 here.
     */
    public function attachment(LeaveRequest $leaveRequest, Attachment $attachment, AttachmentStore $store): StreamedResponse
    {
        return $store->stream($attachment);
    }
}
