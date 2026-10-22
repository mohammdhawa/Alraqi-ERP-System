<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Controllers;

use App\Modules\Attendance\Requests\AbsentTodayRequest;
use App\Modules\Attendance\Requests\DailyReportRequest;
use App\Modules\Attendance\Requests\EmployeeGridRequest;
use App\Modules\Attendance\Requests\MonthlyReportRequest;
use App\Modules\Attendance\Resources\AttendeeResource;
use App\Modules\Attendance\Services\AttendanceReportService;
use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * The attendance reports (work order §4.5 slice 2b; legacy's /attendance/report,
 * /report/monthly, /report/employee and /absent-today). All four read under
 * attendance.records.view, scoped by the caller's reach (GAP-3).
 */
class AttendanceReportController extends Controller
{
    use ApiRespond;

    public function __construct(
        private readonly AttendanceReportService $reports,
        private readonly AttendanceCalendar $calendar,
    ) {}

    /**
     * GET /api/attendance/report — one day's board, today by default.
     */
    public function daily(DailyReportRequest $request): JsonResponse
    {
        return $this->success($this->reports->daily(
            $request->user(),
            $request->validated('date') ?? $this->calendar->today(),
            $request->validated(),
            $request->validated('sort'),
        ));
    }

    /**
     * GET /api/attendance/report/monthly — one row per expected employee.
     */
    public function monthly(MonthlyReportRequest $request): JsonResponse
    {
        return $this->success($this->reports->monthly(
            $request->user(),
            $request->validated('from'),
            $request->validated('to'),
            $request->validated(),
            $request->flag('has_absences'),
            $request->flag('has_missing_checkouts'),
            $request->validated('sort'),
        ));
    }

    /**
     * GET /api/attendance/report/employee — one employee's days.
     */
    public function employee(EmployeeGridRequest $request): JsonResponse
    {
        return $this->success($this->reports->grid(
            $request->user(),
            (int) $request->validated('employee_id'),
            $request->validated('from'),
            $request->validated('to'),
            $request->has('status') ? (int) $request->validated('status') : null,
            filter_var($request->validated('working_days_only', false), FILTER_VALIDATE_BOOLEAN),
            $request->validated('sort'),
        ));
    }

    /**
     * GET /api/attendance/absent-today — expected, and not checked in.
     */
    public function absent(AbsentTodayRequest $request): JsonResponse
    {
        return $this->paginated(AttendeeResource::collection($this->reports->absent(
            $request->user(),
            $request->validated('date') ?? $this->calendar->today(),
            $request->validated(),
            $request->validated('sort'),
            $this->perPage($request),
        )));
    }
}
