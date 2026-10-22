<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\HR\Models\JobTitle;
use App\Modules\HR\Requests\JobTitleIndexRequest;
use App\Modules\HR\Requests\JobTitleRequest;
use App\Modules\HR\Resources\JobTitleResource;
use App\Modules\HR\Services\JobTitleService;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * JobTitleController
 *
 * REST controller for the job-title catalogue — دليل المسميات الوظيفية.
 *
 * ENDPOINTS (module prefix /api/hr):
 *   GET    /api/hr/job-titles             index
 *   POST   /api/hr/job-titles             store
 *   GET    /api/hr/job-titles/{jobTitle}  show
 *   PUT    /api/hr/job-titles/{jobTitle}  update
 *   DELETE /api/hr/job-titles/{jobTitle}  destroy
 *
 * `destroy` can return 409 (JobTitleInUseException) when staff still hold the
 * title or ever held it. The controller does not know that: the model raises,
 * and ApiExceptionRenderer maps the status the exception carries as data (C17).
 */
class JobTitleController extends Controller
{
    use ApiRespond;

    public function __construct(
        private readonly JobTitleService $jobTitleService,
    ) {}

    /**
     * The controller is the only layer that knows a query string exists (C1):
     * it clamps `per_page` and hands the service a validated filter array. An
     * unknown sort or an unparseable boolean filter never reaches the service —
     * JobTitleIndexRequest has already answered 422 (C30, P3).
     */
    public function index(JobTitleIndexRequest $request): JsonResponse
    {
        $jobTitles = $this->jobTitleService->paginate(
            perPage: $this->perPage($request),
            filters: $request->validated(),
            sort: $request->validated('sort'),
        );

        return $this->paginated(
            data: JobTitleResource::collection($jobTitles),
            message: 'تم جلب المسميات الوظيفية.',
        );
    }

    public function store(JobTitleRequest $request): JsonResponse
    {
        $jobTitle = $this->jobTitleService->create($request->validated());

        return $this->created(
            data: new JobTitleResource($jobTitle),
            message: 'تم إنشاء المسمى الوظيفي.',
        );
    }

    public function show(JobTitle $jobTitle): JsonResponse
    {
        return $this->success(
            data: new JobTitleResource($jobTitle),
            message: 'تم جلب المسمى الوظيفي.',
        );
    }

    public function update(JobTitleRequest $request, JobTitle $jobTitle): JsonResponse
    {
        $jobTitle = $this->jobTitleService->update($jobTitle, $request->validated());

        return $this->success(
            data: new JobTitleResource($jobTitle),
            message: 'تم تحديث المسمى الوظيفي.',
        );
    }

    public function destroy(JobTitle $jobTitle): JsonResponse
    {
        $this->jobTitleService->delete($jobTitle);

        return $this->success(message: 'تم حذف المسمى الوظيفي.');
    }
}
