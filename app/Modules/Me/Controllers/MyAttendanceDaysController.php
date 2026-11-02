<?php

declare(strict_types=1);

namespace App\Modules\Me\Controllers;

use App\Modules\Attendance\Services\AttendanceReportService;
use App\Modules\Me\Requests\MyAttendanceDaysRequest;
use App\Modules\Me\Services\SelfEmployee;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * GET /api/me/attendance/days — the employee app's month calendar: §14.3's
 * day grid for the caller's own employee, through the same days() the home
 * screen reads, so the day-state precedence lives in one place.
 *
 * No scope check: the employee is the caller's, resolved from the account.
 */
class MyAttendanceDaysController extends Controller
{
    use ApiRespond;

    public function index(MyAttendanceDaysRequest $request, SelfEmployee $self, AttendanceReportService $reports): JsonResponse
    {
        return $this->success($reports->days(
            $self->of($request->user()),
            $request->validated('from'),
            $request->validated('to'),
        ));
    }
}
