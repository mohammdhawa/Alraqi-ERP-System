<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Controllers;

use App\Modules\Scheduling\Models\ShiftSwap;
use App\Modules\Scheduling\Requests\CancelShiftSwapRequest;
use App\Modules\Scheduling\Requests\ShiftSwapsIndexRequest;
use App\Modules\Scheduling\Resources\ShiftSwapResource;
use App\Modules\Scheduling\Services\ShiftSwapPlan;
use App\Modules\Scheduling\Services\ShiftSwapService;
use App\Shared\Traits\ApiRespond;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/** HR's view of every duty exchange, and the cancellation (work shifts piece 2, spec §6.4). */
class ShiftSwapController extends Controller
{
    use ApiRespond;

    private const RELATIONS = ['requester', 'partner', 'approval'];

    public function index(ShiftSwapsIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $page = ShiftSwap::query()
            ->when(isset($filters['status']), fn (Builder $q) => $q->where('status', (int) $filters['status']))
            ->when(isset($filters['employee_id']), fn (Builder $q) => $q->involving((int) $filters['employee_id']))
            ->when(isset($filters['from']), fn (Builder $q) => $q->where(fn (Builder $d) => $d
                ->where(fn (Builder $r) => $r->whereDate('requester_duty_date', '>=', $filters['from'])->whereDate('requester_duty_date', '<=', $filters['to']))
                ->orWhere(fn (Builder $p) => $p->whereDate('partner_duty_date', '>=', $filters['from'])->whereDate('partner_duty_date', '<=', $filters['to']))))
            ->with(self::RELATIONS)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($this->perPage($request));

        app(ShiftSwapPlan::class)->preload($page->getCollection());

        return $this->paginated(ShiftSwapResource::collection($page));
    }

    public function show(int $swap): JsonResponse
    {
        return $this->success(new ShiftSwapResource(ShiftSwap::query()->with(self::RELATIONS)->findOrFail($swap)));
    }

    public function cancel(CancelShiftSwapRequest $request, int $swap, ShiftSwapService $swaps): JsonResponse
    {
        $cancelled = $swaps->cancel(ShiftSwap::query()->findOrFail($swap), $request->user(), (string) $request->validated('reason'));

        return $this->success(new ShiftSwapResource($cancelled), 'تم إلغاء تبديل المناوبة.');
    }
}
