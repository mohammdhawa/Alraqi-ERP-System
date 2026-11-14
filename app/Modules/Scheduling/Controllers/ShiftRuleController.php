<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Controllers;

use App\Modules\Attendance\Support\AttendanceCalendar;
use App\Modules\Scheduling\Exceptions\SchedulingException;
use App\Modules\Scheduling\Models\Shift;
use App\Modules\Scheduling\Models\ShiftRule;
use App\Modules\Scheduling\Requests\ShiftRuleRequest;
use App\Modules\Scheduling\Resources\ShiftRuleResource;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * A shift's dated rule versions (per-shift rules spec §2.3, §5.1): added from
 * today or later; removed only before they start, never the first.
 */
class ShiftRuleController extends Controller
{
    use ApiRespond;

    public function store(ShiftRuleRequest $request, Shift $shift): JsonResponse
    {
        $refusal = $request->refusal($shift);
        if ($refusal !== null) {
            throw new SchedulingException($refusal);
        }

        $rule = $shift->rules()->create($request->validated() + ['created_by' => $request->user()?->getKey()]);

        return $this->created(new ShiftRuleResource($rule->load('createdBy.employee')), 'تم حفظ قواعد الوردية.');
    }

    public function destroy(Request $request, Shift $shift, ShiftRule $rule): JsonResponse
    {
        if ($rule->effective_from->toDateString() <= app(AttendanceCalendar::class)->today()) {
            throw new SchedulingException('لا يمكن حذف قواعد بدأ العمل بها.');
        }

        $rule->delete();

        return $this->success(message: 'تم حذف قواعد الوردية.');
    }
}
