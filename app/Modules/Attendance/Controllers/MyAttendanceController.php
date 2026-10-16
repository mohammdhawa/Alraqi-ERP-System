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
use App\Modules\Attendance\Services\SelfieAccess;
use App\Modules\Attendance\Services\SelfieStore;
use App\Shared\Traits\ApiRespond;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        $record = new AttendanceRecordResource($result->record->load(AttendanceRecordQuery::RENDER_RELATIONS));

        return $result->created
            ? $this->created($record, 'تم تسجيل الحضور بنجاح.')
            : $this->success($record, 'هذا السجل محفوظ مسبقاً.');
    }

    /**
     * POST /api/me/attendance/sync — the offline queue, entry by entry.
     */
    public function sync(SyncAttendanceRequest $request, AttendanceSyncService $sync): JsonResponse
    {
        $outcome = $sync->sync($request->user(), $request->validated('records'), $request->file('selfies', []));

        return $this->success([
            'succeeded' => AttendanceRecordResource::collection((new Collection($outcome->succeeded))->load(AttendanceRecordQuery::RENDER_RELATIONS)),
            'failed'    => $outcome->failed,
        ], 'تمت مزامنة السجلات.');
    }

    /**
     * GET /api/me/attendance/{record}/selfie — the caller's own selfie, never
     * a colleague's (Q11). Not cached by anything between.
     */
    public function selfie(Request $request, int $record, SelfieAccess $selfies): StreamedResponse
    {
        return Storage::disk(SelfieStore::DISK)->response($selfies->own($request->user(), $record), headers: [
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
