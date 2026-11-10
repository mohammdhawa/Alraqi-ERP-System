<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Controllers;

use App\Modules\Scheduling\Models\WorkSchedule;
use App\Modules\Scheduling\Requests\BulkAssignRequest;
use App\Modules\Scheduling\Services\ScheduleAssigner;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/** One schedule for many people, each assigned or refused on their own (spec §9.2). */
class BulkAssignmentController extends Controller
{
    use ApiRespond;

    public function __invoke(BulkAssignRequest $request, ScheduleAssigner $assigner): JsonResponse
    {
        $results = $assigner->bulk(
            WorkSchedule::query()->findOrFail($request->validated('work_schedule_id')),
            (string) $request->validated('from_date'),
            $request->validated('cycle_start_date'),
            $request->employeeIds(),
            $request->user(),
        );

        $assigned = count(array_filter($results, fn (array $r): bool => $r['assigned']));

        return $this->success($results, "تم إسناد الجدول إلى {$assigned} من ".count($results).'.');
    }
}
