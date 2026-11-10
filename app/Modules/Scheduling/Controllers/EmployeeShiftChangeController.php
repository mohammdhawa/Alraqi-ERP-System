<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\Scheduling\Models\Shift;
use App\Modules\Scheduling\Models\ShiftChange;
use App\Modules\Scheduling\Requests\RecordShiftChangeRequest;
use App\Modules\Scheduling\Requests\ShiftChangesIndexRequest;
use App\Modules\Scheduling\Resources\ShiftChangeResource;
use App\Modules\Scheduling\Services\ShiftChanger;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** A person's one-off changes, under HR's employee routes (work shifts piece 2, spec §5.1). */
class EmployeeShiftChangeController extends Controller
{
    use ApiRespond;

    public function __construct(private readonly ShiftChanger $changer) {}

    public function index(ShiftChangesIndexRequest $request, Employee $employee): JsonResponse
    {
        $changes = ShiftChange::query()->forEmployees([(int) $employee->getKey()])
            ->between((string) $request->validated('from'), (string) $request->validated('to'))
            ->with(['shift', 'createdBy.employee'])->orderBy('date')->get();

        return $this->success(ShiftChangeResource::collection($changes)->resolve($request));
    }

    public function store(RecordShiftChangeRequest $request, Employee $employee): JsonResponse
    {
        $shiftId = $request->validated('shift_id');
        $changes = $this->changer->record(
            $employee,
            (string) $request->validated('from'),
            (string) $request->validated('to'),
            $shiftId === null ? null : Shift::query()->findOrFail($shiftId),
            (string) $request->validated('reason'),
            $request->user(),
        );
        $changes->each->load(['shift', 'createdBy.employee']);

        return $this->created(['shift_changes' => ShiftChangeResource::collection($changes)->resolve($request)], 'تم تعديل الوردية.');
    }

    public function destroy(Request $request, Employee $employee, int $change): JsonResponse
    {
        $this->changer->remove(
            ShiftChange::query()->where('employee_id', $employee->getKey())->findOrFail($change),
            $request->user(),
        );

        return $this->success(message: 'تم إلغاء تعديل الوردية.');
    }
}
