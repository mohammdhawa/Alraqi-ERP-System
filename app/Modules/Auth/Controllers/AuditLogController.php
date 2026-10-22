<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Requests\AuditLogIndexRequest;
use App\Modules\Auth\Resources\AuditLogDetailResource;
use App\Modules\Auth\Resources\AuditLogResource;
use App\Modules\Auth\Services\AuditTrailService;
use App\Shared\Models\AuditLog;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * AuditLogController
 *
 * سجل التدقيق — the read surface over `audit_logs`.
 *
 * ENDPOINTS (prefixed /api/auth):
 *   GET /api/auth/audit-logs            → index (permission:auth.audit.view)
 *   GET /api/auth/audit-logs/{auditLog} → show  (permission:auth.audit.view)
 *
 * TWO ACTIONS, AND THERE WILL NEVER BE MORE. No store, no update, no destroy —
 * the table is append-only by design, no permission exists that could authorize
 * a mutation, and in production the DB user should hold INSERT and SELECT only.
 * If a future requirement sounds like "let an admin correct an audit row", the
 * answer is a new row, not an edit.
 *
 * READING IT WRITES TO IT. These routes carry the `audit` middleware like every
 * other authenticated route in the group, so each page of this screen appends
 * one `api_request` row to the table it is showing. That is deliberate and not a
 * quirk to engineer around: "who read the audit trail, and when" is itself a
 * security-relevant fact, and it is the notifications carve-out — polled reads
 * that would flood the trail with no information — that is the exception here,
 * not the rule. The consequence for clients is stated in the API doc: do not
 * poll this endpoint.
 */
class AuditLogController extends Controller
{
    use ApiRespond;

    public function __construct(
        private readonly AuditTrailService $auditTrailService,
    ) {}

    /**
     * The controller is the only layer that knows a query string exists (C1):
     * it clamps `per_page` and hands the service an int plus a validated filter
     * array, so the service stays callable from a console command.
     */
    public function index(AuditLogIndexRequest $request): JsonResponse
    {
        $logs = $this->auditTrailService->paginate(
            perPage: $this->perPage($request),
            filters: $request->validated(),
            sort: $request->validated('sort'),
        );

        return $this->paginated(
            data: AuditLogResource::collection($logs),
            message: 'تم جلب سجل التدقيق.',
        );
    }

    /**
     * Route-model binding, not a lookup (C3). The eager loads are the response
     * layer's business and belong where the Resource is chosen: both
     * AuditLogResource and its detail subclass dereference `user.employee` and
     * `auditable`, and a Resource that lazy-loads breaks its own contract.
     */
    public function show(AuditLog $auditLog): JsonResponse
    {
        return $this->success(
            data: new AuditLogDetailResource($auditLog->load(['user.employee', 'auditable'])),
            message: 'تم جلب تفاصيل السجل.',
        );
    }
}
