<?php

declare(strict_types=1);

namespace App\Shared\Middleware;

use App\Shared\Support\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * SetRequestContext
 *
 * Stage 1 (global transport). Resolves the request's correlation id and
 * transport facts once, hands them to the shared RequestContext, and echoes the
 * id back as X-Request-Id (§6 Headers, GAP-5).
 *
 * WHY IT IS PREPENDED to the api group: everything downstream — the throttle,
 * auth:sanctum, the permission check, controllers, services, and every model
 * audit write — must see the same context. An audit row written by a middleware
 * that ran before this one could not be correlated with the response the client
 * actually received.
 *
 * WHY IT ACCEPTS AN INBOUND X-Request-Id: the dashboard and the Flutter app can
 * generate one per user action, which makes a single tap traceable across a
 * retry and a token refresh. It is regenerated when absent or implausible —
 * never trusted blindly into a log or a database column.
 *
 * This middleware is deliberately module-agnostic and carries no domain
 * knowledge (§3 layer contract for Middleware), and it builds no JSON envelope.
 */
class SetRequestContext
{
    /**
     * Cap on an accepted inbound id. Long enough for a UUID or a trace id,
     * short enough that nothing unbounded reaches a log line or a DB column.
     */
    private const MAX_INBOUND_ID_LENGTH = 64;

    public function __construct(
        private readonly RequestContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolveRequestId($request);

        $this->context->hydrate(
            requestId: $requestId,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        // Identity is not known yet — auth:sanctum is a route middleware and
        // runs after this. Hand over the lookup instead of the value; the
        // context memoizes it the first time something asks.
        $this->context->resolveUserUsing(
            static fn (): ?int => $request->user()?->getKey(),
        );

        $response = $next($request);

        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }

    /**
     * Use the client's id when it is present and plausible; otherwise mint one.
     */
    private function resolveRequestId(Request $request): string
    {
        $inbound = $request->headers->get('X-Request-Id');

        if (is_string($inbound)
            && $inbound !== ''
            && strlen($inbound) <= self::MAX_INBOUND_ID_LENGTH
            && preg_match('/^[A-Za-z0-9._-]+$/', $inbound) === 1
        ) {
            return $inbound;
        }

        return (string) Str::uuid();
    }
}
