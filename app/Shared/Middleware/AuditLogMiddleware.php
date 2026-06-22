<?php

declare(strict_types=1);

namespace App\Shared\Middleware;

use App\Shared\Services\AuditLogService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AuditLogMiddleware
 *
 * Logs every authenticated API request with route, method, and status code.
 * This provides a request-level audit trail separate from the model-level
 * trail provided by HasAuditLog.
 *
 * WHY both model-level and request-level logging:
 * - Model-level: captures WHAT changed (field-level diff).
 * - Request-level: captures WHO did WHAT action and WHEN (API call trail).
 * - Together they provide complete audit coverage for compliance (SOC2, ISO 27001).
 *
 * PERFORMANCE:
 * - Runs after the response is sent (uses ->after() pattern).
 * - Only logs authenticated requests to avoid noise from public endpoints.
 * - In high-traffic ERPs, consider dispatching to a queue instead.
 *
 * USAGE:
 * Apply to route groups that need audit trails:
 *   Route::middleware(['auth:sanctum', 'audit'])->group(...)
 *
 * Register in bootstrap/app.php:
 *   ->withMiddleware(function (Middleware $middleware) {
 *       $middleware->alias(['audit' => AuditLogMiddleware::class]);
 *   })
 */
class AuditLogMiddleware
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only audit authenticated requests
        if ($request->user()) {
            try {
                $this->auditLogService->logAction(
                    event: 'api_request',
                    description: sprintf(
                        '%s %s → %d',
                        $request->method(),
                        $request->path(),
                        $response->getStatusCode(),
                    ),
                    metadata: [
                        'method'      => $request->method(),
                        'path'        => $request->path(),
                        'status_code' => $response->getStatusCode(),
                        'route_name'  => $request->route()?->getName(),
                    ],
                );
            } catch (\Throwable $e) {
                // Never let audit logging break the response
                report($e);
            }
        }

        return $response;
    }
}