<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Controllers;

use App\Modules\Attendance\Support\ShiftRules;
use App\Modules\Scheduling\Exceptions\SchedulingException;
use App\Modules\Scheduling\Models\Shift;
use App\Modules\Scheduling\Requests\ShiftRequest;
use App\Modules\Scheduling\Requests\ShiftRuleRequest;
use App\Modules\Scheduling\Resources\ShiftResource;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/** The shift catalogue (work shifts spec §9.2): HR only. */
class ShiftController extends Controller
{
    use ApiRespond;

    public function index(Request $request): JsonResponse
    {
        return $this->success(ShiftResource::collection(Shift::query()->with('rules')->orderBy('code')->get())->resolve($request));
    }

    public function show(Shift $shift): JsonResponse
    {
        return $this->success((new ShiftResource($shift->load('rules.createdBy.employee')))->withVersions());
    }

    /** The shift, and its first rules version from the given fields (per-shift rules spec §5.1). */
    public function store(ShiftRequest $request): JsonResponse
    {
        $refusal = $request->refusal();
        if ($refusal !== null) {
            throw new SchedulingException($refusal);
        }

        $shift = DB::transaction(function () use ($request): Shift {
            $fields = array_diff_key($request->validated(), ShiftRuleRequest::fieldRules('sometimes'));
            $shift = Shift::create($fields);
            $rules = $request->ruleFields();
            if ($rules !== []) {
                $shift->rules()->where('effective_from', ShiftRules::FIRST_DAY)->sole()->update($rules);
            }

            return $shift;
        });

        return $this->created(new ShiftResource($shift->fresh('rules')), 'تم إنشاء الوردية.');
    }

    public function update(ShiftRequest $request, Shift $shift): JsonResponse
    {
        $shift->update($request->validated());

        return $this->success(new ShiftResource($shift->fresh()), 'تم تعديل الوردية.');
    }

    public function destroy(Shift $shift): JsonResponse
    {
        $shift->delete();

        return $this->success(message: 'تم حذف الوردية.');
    }
}
