<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Controllers;

use App\Modules\Scheduling\Models\WorkSchedule;
use App\Modules\Scheduling\Requests\WorkScheduleRequest;
use App\Modules\Scheduling\Resources\WorkScheduleResource;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/** Schedules, with their days in one body (work shifts spec §9.2): HR only. */
class WorkScheduleController extends Controller
{
    use ApiRespond;

    public function index(Request $request): JsonResponse
    {
        $schedules = WorkSchedule::query()->with('days.shift')->orderBy('code')->get();

        return $this->success(WorkScheduleResource::collection($schedules)->resolve($request));
    }

    public function show(WorkSchedule $schedule): JsonResponse
    {
        return $this->success(new WorkScheduleResource($schedule));
    }

    public function store(WorkScheduleRequest $request): JsonResponse
    {
        $schedule = WorkSchedule::createWithDays($request->safe()->except('days'), $request->days());

        return $this->created(new WorkScheduleResource($schedule->fresh()), 'تم إنشاء الجدول.');
    }

    public function update(WorkScheduleRequest $request, WorkSchedule $schedule): JsonResponse
    {
        DB::transaction(function () use ($request, $schedule): void {
            $schedule->update($request->safe()->except('days'));
            if ($request->has('days')) {
                $schedule->replaceDays($request->days());
            }
        });

        return $this->success(new WorkScheduleResource($schedule->fresh()), 'تم تعديل الجدول.');
    }

    public function destroy(WorkSchedule $schedule): JsonResponse
    {
        $schedule->delete();

        return $this->success(message: 'تم حذف الجدول.');
    }
}
