<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\HR\Requests\EmployeeImportExclusionsRequest;
use App\Modules\HR\Requests\EmployeeImportExecuteRequest;
use App\Modules\HR\Requests\EmployeeImportRequest;
use App\Modules\HR\Resources\EmployeeImportRunResource;
use App\Modules\HR\Services\EmployeeImportService;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The upload import (EMPLOYEE_IMPORT_UPLOAD_DESIGN.md §4.2). Who may call it
 * is decided on the route (permission + EmployeeImportRunPolicy); whose run it
 * is, by the service (another's run is 404).
 */
class EmployeeImportController extends Controller
{
    use ApiRespond;

    public function __construct(
        private readonly EmployeeImportService $imports,
    ) {}

    public function store(EmployeeImportRequest $request): JsonResponse
    {
        $run = $this->imports->check($request->user(), $request->file('workbook'));

        return $this->created(
            data: new EmployeeImportRunResource($run),
            message: 'تم فحص الملف. راجع التقرير قبل الاستيراد.',
        );
    }

    public function show(Request $request, int $run): JsonResponse
    {
        return $this->success(
            data: new EmployeeImportRunResource($this->imports->findOwned($run, $request->user())),
            message: 'تم جلب الاستيراد.',
        );
    }

    /** Leave rows out of this import; the report comes back re-checked (§12). */
    public function exclusions(EmployeeImportExclusionsRequest $request, int $run): JsonResponse
    {
        return $this->success(
            data: new EmployeeImportRunResource($this->imports->exclude($run, $request->user(), $request->validated('rows'))),
            message: 'تم تطبيق الاستبعاد وإعادة الفحص.',
        );
    }

    /**
     * The real import. `credentials` is the only place its temporary
     * passwords ever appear: the screen saves the file at once (U4).
     */
    public function execute(EmployeeImportExecuteRequest $request, int $run): JsonResponse
    {
        $result = $this->imports->execute($run, $request->user(), $request->validated('excluded_rows'));

        return $this->success(
            data: [
                'run'         => new EmployeeImportRunResource($result['run']),
                'credentials' => $result['credentials'],
            ],
            message: 'تم الاستيراد. سلّم ملف كلمات المرور للموارد البشرية ثم احذفه.',
        );
    }

    /** The report as xlsx — a file, so no envelope, as the photo and selfie streams. */
    public function report(Request $request, int $run): BinaryFileResponse
    {
        $path = $this->imports->reportFile($this->imports->findOwned($run, $request->user()));

        return response()
            ->download($path, "تقرير-استيراد-الموظفين-{$run}.xlsx", [
                'Content-Type'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store',
            ])
            ->deleteFileAfterSend();
    }
}
