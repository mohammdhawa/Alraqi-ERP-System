<?php

declare(strict_types=1);

namespace App\Modules\Me\Controllers;

use App\Modules\HR\Resources\EmployeeResource;
use App\Modules\HR\Services\EmployeeService;
use App\Modules\Me\Resources\MyProfileResource;
use App\Modules\Me\Services\SelfEmployee;
use App\Shared\Support\RequestRowScopes;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * GET /api/me/profile — the caller's own employee record (work order §4.8,
 * requirements §6.1), with their own pay block and national id.
 */
class MyProfileController extends Controller
{
    use ApiRespond;

    public function show(Request $request, SelfEmployee $self, EmployeeService $employees): JsonResponse
    {
        $employee = $employees->prepareForResource($self->of($request->user()));
        $employee->load('personalPhoto');

        // The caller's own row opens by the self rule; warming still matters
        // for a caller whose grants reach it anyway — same answer, no query
        // from inside the Resource.
        RequestRowScopes::warm($request, EmployeeResource::FIELD_PERMISSIONS);

        return $this->success(
            data: MyProfileResource::forSelf($employee),
            message: 'تم جلب الملف الوظيفي.',
        );
    }
}
