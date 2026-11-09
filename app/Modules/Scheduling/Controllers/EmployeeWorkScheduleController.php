<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\Scheduling\Models\EmployeeWorkSchedule;
use App\Modules\Scheduling\Models\WorkSchedule;
use App\Modules\Scheduling\Requests\AssignWorkScheduleRequest;
use App\Modules\Scheduling\Requests\EndWorkScheduleRequest;
use App\Modules\Scheduling\Resources\EmployeeWorkScheduleResource;
use App\Modules\Scheduling\Services\ScheduleAssigner;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** A person's schedule history, under HR's employee routes (work shifts spec §9.2). */
class EmployeeWorkScheduleController extends Controller
{
    use ApiRespond;

    public function __construct(private readonly ScheduleAssigner $assigner) {}

    public function index(Request $request, Employee $employee): JsonResponse
    {
        $spans = EmployeeWorkSchedule::query()->where('employee_id', $employee->getKey())->orderByDesc('from_date')->get();

        return $this->success(EmployeeWorkScheduleResource::collection($spans)->resolve($request));
    }

    public function store(AssignWorkScheduleRequest $request, Employee $employee): JsonResponse
    {
        $span = $this->assigner->assign(
            $employee,
            WorkSchedule::query()->findOrFail($request->validated('work_schedule_id')),
            (string) $request->validated('from_date'),
            $request->validated('cycle_start_date'),
            $request->user(),
        );

        return $this->created(new EmployeeWorkScheduleResource($span->fresh()), 'تم إسناد الجدول.');
    }

    public function update(EndWorkScheduleRequest $request, Employee $employee, int $assignment): JsonResponse
    {
        $span = $this->assigner->end($this->spanOf($employee, $assignment), (string) $request->validated('to_date'));

        return $this->success(new EmployeeWorkScheduleResource($span), 'تم تحديد نهاية الجدول.');
    }

    public function destroy(Employee $employee, int $assignment): JsonResponse
    {
        $this->assigner->remove($this->spanOf($employee, $assignment));

        return $this->success(message: 'تم حذف الجدول.');
    }

    /** Another person's span is a 404, as if it did not exist. */
    private function spanOf(Employee $employee, int $id): EmployeeWorkSchedule
    {
        return EmployeeWorkSchedule::query()->where('employee_id', $employee->getKey())->findOrFail($id);
    }
}
