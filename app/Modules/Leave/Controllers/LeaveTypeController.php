<?php

declare(strict_types=1);

namespace App\Modules\Leave\Controllers;

use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Requests\LeaveTypesIndexRequest;
use App\Modules\Leave\Requests\StoreLeaveTypeRequest;
use App\Modules\Leave\Requests\UpdateLeaveTypeRequest;
use App\Modules\Leave\Resources\LeaveTypeResource;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * The leave-type catalogue — legacy's LeaveTypeController (LEAVE_BUILD.md §3).
 *
 * Not paginated: nine rows today, and every picker needs all of them at once,
 * as legacy's list.
 */
class LeaveTypeController extends Controller
{
    use ApiRespond;

    /**
     * GET /api/leave/types
     */
    public function index(LeaveTypesIndexRequest $request): JsonResponse
    {
        $query = LeaveType::query()->ordered()->active(! $request->wantsRetired());

        match ($request->validated('for')) {
            'requests' => $query->forRequests(),
            'excuses'  => $query->forExcuses(),
            default    => null,
        };

        if ($request->has('deducts_balance')) {
            $query->deducting($request->boolean('deducts_balance'));
        }

        return $this->success(LeaveTypeResource::collection($query->get())->resolve($request));
    }

    /**
     * POST /api/leave/types
     */
    public function store(StoreLeaveTypeRequest $request): JsonResponse
    {
        return $this->created(new LeaveTypeResource(LeaveType::create($request->validated())->fresh()), 'تم إنشاء نوع الإجازة.');
    }

    /**
     * PATCH /api/leave/types/{leaveType}
     */
    public function update(UpdateLeaveTypeRequest $request, LeaveType $leaveType): JsonResponse
    {
        $leaveType->update($request->validated());

        return $this->success(new LeaveTypeResource($leaveType->fresh()), 'تم تعديل نوع الإجازة.');
    }

    /**
     * DELETE /api/leave/types/{leaveType} — a type leave was filed under is
     * deactivated instead, so its rows keep what they were filed as (legacy).
     */
    public function destroy(LeaveType $leaveType): JsonResponse
    {
        if ($leaveType->leaveRequests()->withTrashed()->exists()) {
            $leaveType->update(['is_active' => false]);

            return $this->success(new LeaveTypeResource($leaveType->fresh()), 'نوع الإجازة مستخدم، فتم إيقافه بدلاً من حذفه.');
        }

        $leaveType->delete();

        return $this->success(message: 'تم حذف نوع الإجازة.');
    }
}
