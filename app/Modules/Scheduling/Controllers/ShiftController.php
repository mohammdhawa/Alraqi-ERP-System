<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Controllers;

use App\Modules\Scheduling\Models\Shift;
use App\Modules\Scheduling\Requests\ShiftRequest;
use App\Modules\Scheduling\Resources\ShiftResource;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** The shift catalogue (work shifts spec §9.2): HR only. */
class ShiftController extends Controller
{
    use ApiRespond;

    public function index(Request $request): JsonResponse
    {
        return $this->success(ShiftResource::collection(Shift::query()->orderBy('code')->get())->resolve($request));
    }

    public function show(Shift $shift): JsonResponse
    {
        return $this->success(new ShiftResource($shift));
    }

    public function store(ShiftRequest $request): JsonResponse
    {
        return $this->created(new ShiftResource(Shift::create($request->validated())->fresh()), 'تم إنشاء الوردية.');
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
