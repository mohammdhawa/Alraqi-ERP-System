<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Controllers;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Scheduling\Exceptions\SchedulingException;
use App\Modules\Scheduling\Models\ShiftSwap;
use App\Modules\Scheduling\Requests\FileShiftSwapRequest;
use App\Modules\Scheduling\Resources\ShiftSwapResource;
use App\Modules\Scheduling\Services\ShiftSwapService;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * A guard's own duty exchanges, under /api/me (work shifts piece 2, spec
 * §6.1): resolved from the caller's account; an exchange he is not part of
 * is a 404.
 */
class MyShiftSwapController extends Controller
{
    use ApiRespond;

    public function __construct(private readonly ShiftSwapService $swaps) {}

    public function options(Request $request): JsonResponse
    {
        return $this->success($this->swaps->options($this->employeeOf($request->user())));
    }

    public function index(Request $request): JsonResponse
    {
        $page = ShiftSwap::query()->involving((int) $this->employeeOf($request->user())->getKey())
            ->with(['requester', 'partner', 'approval'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(min($this->perPage($request), 50));

        return $this->paginated(ShiftSwapResource::collection($page));
    }

    public function store(FileShiftSwapRequest $request): JsonResponse
    {
        $swap = $this->swaps->file($this->employeeOf($request->user()), $request->user(), $request->validated());

        return $this->created(['shift_swap' => (new ShiftSwapResource($swap->load(['requester', 'partner', 'approval'])))->resolve($request)], 'تم تقديم طلب تبديل المناوبة.');
    }

    public function show(Request $request, int $swap): JsonResponse
    {
        return $this->success(new ShiftSwapResource($this->mine($request->user(), $swap)));
    }

    public function cancel(Request $request, int $swap): JsonResponse
    {
        $withdrawn = $this->swaps->withdraw($this->mine($request->user(), $swap), $request->user());

        return $this->success(new ShiftSwapResource($withdrawn->load(['requester', 'partner', 'approval'])), 'تم سحب طلب تبديل المناوبة.');
    }

    private function mine(User $user, int $id): ShiftSwap
    {
        return ShiftSwap::query()->involving((int) $this->employeeOf($user)->getKey())->with(['requester', 'partner', 'approval'])->findOrFail($id);
    }

    private function employeeOf(User $user): Employee
    {
        return ($user->employee_id === null ? null : Employee::query()->find($user->employee_id))
            ?? throw new SchedulingException('لا يوجد موظف مرتبط بهذا الحساب.', Response::HTTP_FORBIDDEN);
    }
}
