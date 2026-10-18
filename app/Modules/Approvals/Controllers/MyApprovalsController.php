<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Controllers;

use App\Modules\Approvals\Requests\MyApprovalsRequest;
use App\Modules\Approvals\Resources\ApprovalInboxResource;
use App\Modules\Approvals\Services\ApprovalInbox;
use App\Modules\Approvals\Support\ApprovalRelations;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * GET /api/me/approvals — the approver's inbox (design §7), routed under
 * /api/me because it is the caller's own: their steps, found from their
 * employee, never from an id in the request.
 */
class MyApprovalsController extends Controller
{
    use ApiRespond;

    public function index(MyApprovalsRequest $request, ApprovalInbox $inbox): JsonResponse
    {
        $user = $request->user();
        $steps = $request->wantsDecided() ? $inbox->decidedBy($user) : $inbox->pendingFor($user);

        return $this->paginated(ApprovalInboxResource::collection(
            $steps->with(ApprovalRelations::inbox())->paginate($this->perPage($request)),
        ));
    }
}
