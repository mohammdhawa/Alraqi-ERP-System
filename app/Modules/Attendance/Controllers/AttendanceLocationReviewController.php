<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Controllers;

use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Requests\ApproveLocationReviewRequest;
use App\Modules\Attendance\Requests\BulkApproveLocationReviewRequest;
use App\Modules\Attendance\Resources\AttendanceRecordResource;
use App\Modules\Attendance\Services\AttendanceLocationApprovalService;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class AttendanceLocationReviewController extends Controller
{
    use ApiRespond;

    public function approve(ApproveLocationReviewRequest $request, AttendanceRecord $record, AttendanceLocationApprovalService $service): JsonResponse
    {
        return $this->success(new AttendanceRecordResource(
            $service->approve($record, $request->user(), $request->validated('note')),
        ), 'تم اعتماد مراجعة الموقع.');
    }

    public function bulk(BulkApproveLocationReviewRequest $request, AttendanceLocationApprovalService $service): JsonResponse
    {
        $data = $request->validated();

        return $this->success($service->bulk(
            $request->user(),
            $data['record_ids'] ?? null,
            $data['matching'] ?? null,
            $data['note'] ?? null,
        ), 'تمت معالجة مراجعات الموقع المحددة.');
    }
}
