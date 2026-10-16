<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Controllers;

use App\Modules\Attendance\Requests\MyAttendanceRecordsRequest;
use App\Modules\Attendance\Requests\RecordAttendanceRequest;
use App\Modules\Attendance\Requests\SyncAttendanceRequest;
use App\Modules\Attendance\Resources\AttendanceRecordResource;
use App\Modules\Attendance\Services\AttendanceRecordQuery;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Attendance\Services\AttendanceSyncService;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * The caller's own attendance, under /api/me/attendance (work order §4.5,
 * §4.8). Self-scoped: every action resolves the employee from the account,
 * never from the request (C7).
 */
class MyAttendanceController extends Controller
{
    use ApiRespond;

    /**
     * GET /api/me/attendance — the caller's records, today by default.
     */
    public function index(MyAttendanceRecordsRequest $request, AttendanceRecordQuery $records): JsonResponse
    {
        return $this->paginated(AttendanceRecordResource::collection(
            $records->mine($request->user(), $request->validated(), $this->perPage($request)),
        ));
    }

    /**
     * POST /api/me/attendance — one check-in or check-out. 201 when saved;
     * 200 with the saved record when its client_uuid was already saved (Q12).
     */
    public function store(RecordAttendanceRequest $request, AttendanceService $attendance): JsonResponse
    {
        $result = $attendance->recordFor($request->user(), $request->validated(), $request->file('selfie'));

        return $result->created
            ? $this->created(new AttendanceRecordResource($result->record), 'تم تسجيل الحضور بنجاح.')
            : $this->success(new AttendanceRecordResource($result->record), 'هذا السجل محفوظ مسبقاً.');
    }

    /**
     * POST /api/me/attendance/sync — the offline queue, entry by entry.
     */
    public function sync(SyncAttendanceRequest $request, AttendanceSyncService $sync): JsonResponse
    {
        $outcome = $sync->sync($request->user(), $request->validated('records'), $request->file('selfies', []));

        return $this->success([
            'succeeded' => AttendanceRecordResource::collection($outcome->succeeded),
            'failed'    => $outcome->failed,
        ], 'تمت مزامنة السجلات.');
    }
}
