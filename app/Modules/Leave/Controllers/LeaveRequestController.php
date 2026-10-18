<?php

declare(strict_types=1);

namespace App\Modules\Leave\Controllers;

use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Resources\LeaveRequestResource;
use App\Modules\Leave\Services\LeaveRequestQuery;
use App\Shared\Models\Attachment;
use App\Shared\Services\AttachmentStore;
use App\Shared\Support\RequestRowScopes;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One leave, read by whoever LeaveRequestPolicy admits — the request screen
 * an approver opens from the inbox (LEAVE_BUILD.md §4). The route's `can:`
 * runs before anything here.
 */
class LeaveRequestController extends Controller
{
    use ApiRespond;

    /**
     * GET /api/leave/requests/{leaveRequest}
     */
    public function show(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        RequestRowScopes::warm($request, ['approvals.reassign']);

        return $this->success(new LeaveRequestResource($leaveRequest->load(LeaveRequestQuery::renderRelations())));
    }

    /**
     * GET /api/leave/requests/{leaveRequest}/attachments/{attachment} —
     * streamed, never a URL. The attachment is bound scoped to the leave: a
     * file of another request is a 404 here.
     */
    public function attachment(LeaveRequest $leaveRequest, Attachment $attachment, AttachmentStore $store): StreamedResponse
    {
        return $store->stream($attachment);
    }
}
