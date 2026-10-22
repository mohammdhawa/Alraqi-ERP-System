<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Controllers;

use App\Modules\Approvals\Enums\Decision;
use App\Modules\Approvals\Models\Approval;
use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Approvals\Requests\DecideStepRequest;
use App\Modules\Approvals\Requests\ReassignStepRequest;
use App\Modules\Approvals\Resources\ApprovalResource;
use App\Modules\Approvals\Services\ApprovalService;
use App\Modules\Approvals\Support\ApprovalRelations;
use App\Modules\HR\Models\Employee;
use App\Shared\Support\RequestRowScopes;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The request screen and what is done from it (design §7) — one API for the
 * app and the dashboard (2026-10-14).
 *
 * Who may reach a route is its `can:` (ApprovalPolicy), which runs before any
 * body is read; what makes the action valid is ApprovalService's. Every
 * response is the approval's live state, read fresh after the action, so the
 * client redraws from what the server now holds.
 */
class ApprovalController extends Controller
{
    use ApiRespond;

    /**
     * GET /api/approvals/{approval}
     */
    public function show(Request $request, Approval $approval): JsonResponse
    {
        return $this->success($this->live($request, $approval));
    }

    /**
     * POST /api/approvals/{approval}/steps/{step}/approve
     */
    public function approve(DecideStepRequest $request, Approval $approval, ApprovalStep $step, ApprovalService $engine): JsonResponse
    {
        $engine->act($step, $request->user(), Decision::Approve, $request->validated('note'));

        return $this->success($this->live($request, $approval), 'تمت الموافقة.');
    }

    /**
     * POST /api/approvals/{approval}/steps/{step}/reject
     */
    public function reject(DecideStepRequest $request, Approval $approval, ApprovalStep $step, ApprovalService $engine): JsonResponse
    {
        $engine->act($step, $request->user(), Decision::Reject, $request->validated('note'));

        return $this->success($this->live($request, $approval), 'تم الرفض.');
    }

    /**
     * POST /api/approvals/{approval}/withdraw — the requester, before any
     * decision (§9 Q1 = B). A consumer may offer the same through its own
     * route (a leave request's cancel); the engine call is the same.
     */
    public function withdraw(Request $request, Approval $approval, ApprovalService $engine): JsonResponse
    {
        $engine->withdraw($approval, $request->user());

        return $this->success($this->live($request, $approval), 'تم سحب الطلب.');
    }

    /**
     * PATCH /api/approvals/{approval}/steps/{step}/assignee
     */
    public function reassign(ReassignStepRequest $request, Approval $approval, ApprovalStep $step, ApprovalService $engine): JsonResponse
    {
        $to = Employee::query()->findOrFail($request->validated('employee_id'));

        $engine->reassign($step, $to, $request->user(), $request->validated('reason'));

        return $this->success($this->live($request, $approval), 'تمت إعادة إسناد الخطوة.');
    }

    /**
     * The approval as it stands now, ready to render without a query: its
     * relations eager-loaded and the caller's reassign reach resolved.
     */
    private function live(Request $request, Approval $approval): ApprovalResource
    {
        RequestRowScopes::warm($request, ['approvals.reassign']);

        return new ApprovalResource($approval->fresh()->load(ApprovalRelations::liveState()));
    }
}
