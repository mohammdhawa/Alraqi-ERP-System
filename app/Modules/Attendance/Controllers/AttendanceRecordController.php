<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Controllers;

use App\Modules\Attendance\Enums\RejectionReason;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Requests\AttendanceRecordsRequest;
use App\Modules\Attendance\Requests\CorrectCheckoutRequest;
use App\Modules\Attendance\Requests\RejectAttendanceRecordRequest;
use App\Modules\Attendance\Resources\AttendanceRecordResource;
use App\Modules\Attendance\Services\AttendanceOversightService;
use App\Modules\Attendance\Services\AttendanceRecordQuery;
use App\Modules\Attendance\Services\SelfieAccess;
use App\Modules\Attendance\Services\SelfieStore;
use App\Shared\Traits\ApiRespond;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HR's and managers' side of attendance records (work order §4.5 slice 2;
 * legacy's /attendance/records, correctCheckout, reject, unreject).
 *
 * The verb is the route's `permission:`; the row is the route's `can:`
 * (AttendanceRecordPolicy), which runs before any body is read.
 */
class AttendanceRecordController extends Controller
{
    use ApiRespond;

    /**
     * GET /api/attendance/records — everyone the caller's grant reaches.
     */
    public function index(AttendanceRecordsRequest $request, AttendanceRecordQuery $records): JsonResponse
    {
        return $this->paginated(AttendanceRecordResource::collection(
            $records->oversight($request->user(), $request->validated(), $this->perPage($request)),
        ));
    }

    /**
     * GET /api/attendance/records/{record}/selfie
     */
    public function selfie(AttendanceRecord $record, SelfieAccess $selfies): StreamedResponse
    {
        return Storage::disk(SelfieStore::DISK)->response($selfies->of($record), headers: [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * PATCH /api/attendance/records/{record}/checkout — the forgotten check-out.
     */
    public function correctCheckout(CorrectCheckoutRequest $request, AttendanceRecord $record, AttendanceOversightService $oversight): JsonResponse
    {
        $day = $oversight->correctCheckout($record, $request->user(), $request->validated('checked_out_at'), $request->validated('note'));

        return $this->success([
            'check_in'  => new AttendanceRecordResource($day['check_in']->load(AttendanceRecordQuery::RENDER_RELATIONS)),
            'check_out' => new AttendanceRecordResource($day['check_out']->load(AttendanceRecordQuery::RENDER_RELATIONS)),
        ], 'تم تصحيح الانصراف بنجاح.');
    }

    /**
     * PATCH /api/attendance/records/{record}/reject — refuse it, and tell the
     * employee why. Every row the decision took down is returned, so the
     * caller can redraw the whole day.
     */
    public function reject(RejectAttendanceRecordRequest $request, AttendanceRecord $record, AttendanceOversightService $oversight): JsonResponse
    {
        $rows = $oversight->reject($record, $request->user(), RejectionReason::from((int) $request->validated('reason')), $request->validated('note'));

        return $this->success($this->day($rows, 'rejected_records'), 'تم رفض التسجيل وإشعار الموظف.');
    }

    /**
     * DELETE /api/attendance/records/{record}/reject — undo a refusal.
     */
    public function unreject(AttendanceRecord $record, AttendanceOversightService $oversight): JsonResponse
    {
        return $this->success($this->day($oversight->unreject($record), 'restored_records'), 'تم التراجع عن رفض التسجيل.');
    }

    /**
     * @param  array<int, AttendanceRecord>  $rows  the decision's rows, its root first
     * @return array<string, mixed>
     */
    private function day(array $rows, string $key): array
    {
        $rows = (new Collection($rows))->load(AttendanceRecordQuery::RENDER_RELATIONS);

        return [
            'record' => new AttendanceRecordResource($rows->first()),
            $key     => AttendanceRecordResource::collection($rows),
        ];
    }
}
