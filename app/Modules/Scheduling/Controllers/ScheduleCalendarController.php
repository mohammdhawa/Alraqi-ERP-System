<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Resources\EmployeeStub;
use App\Modules\Scheduling\Requests\ScheduleCalendarRequest;
use App\Modules\Scheduling\Services\ScheduleResolver;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Controller;

/**
 * Everyone's expected shift per date over a range, paginated by employee —
 * the dashboard's calendar view (spec §9.2). HR only.
 */
class ScheduleCalendarController extends Controller
{
    use ApiRespond;

    public function __invoke(ScheduleCalendarRequest $request, ScheduleResolver $resolver): JsonResponse
    {
        $page = Employee::query()
            ->when($request->has('department_id'), fn ($q) => $q->where('department_id', (int) $request->validated('department_id')))
            ->when($request->has('employee_id'), fn ($q) => $q->whereKey((int) $request->validated('employee_id')))
            ->orderBy('first_name')->orderBy('id')
            ->paginate($this->perPage($request));

        $from = (string) $request->validated('from');
        $to = (string) $request->validated('to');
        $calendar = $resolver->calendarFor($page->getCollection()->modelKeys(), $from, $to);

        $page->through(fn (Employee $employee): array => [
            'employee' => EmployeeStub::from($employee),
            'days'     => array_map(static fn (array $day): array => [
                'date'   => $day['date'],
                'shift'  => $day['shift']?->toArray(),
                'source' => $day['source'],
            ], $calendar[(int) $employee->getKey()] ?? []),
        ]);

        return $this->paginated(JsonResource::collection($page), extra: ['from' => $from, 'to' => $to]);
    }
}
