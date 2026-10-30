<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Controllers;

use App\Modules\Attendance\Models\WorkSite;
use App\Modules\Attendance\Requests\StoreWorkSiteRequest;
use App\Modules\Attendance\Requests\UpdateWorkSiteRequest;
use App\Modules\Attendance\Resources\WorkSiteResource;
use App\Modules\Attendance\Services\WorkSiteAssignmentService;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class WorkSiteController extends Controller
{
    use ApiRespond;

    public function index(Request $request): JsonResponse
    {
        return $this->paginated(WorkSiteResource::collection(
            WorkSite::query()->with(['versions' => fn ($query) => $query->orderBy('effective_from')])
                ->orderBy('name')->paginate($this->perPage($request)),
        ));
    }

    public function store(StoreWorkSiteRequest $request, WorkSiteAssignmentService $sites): JsonResponse
    {
        return $this->created(new WorkSiteResource($sites->createSite($request->validated())));
    }

    public function update(UpdateWorkSiteRequest $request, WorkSite $site, WorkSiteAssignmentService $sites): JsonResponse
    {
        return $this->success(new WorkSiteResource($sites->updateSite($site, $request->validated())));
    }

    public function archive(WorkSite $site, WorkSiteAssignmentService $sites): JsonResponse
    {
        return $this->success(new WorkSiteResource($sites->archiveSite($site)));
    }
}
