<?php

declare(strict_types=1);

namespace App\Shared\Support;

use Closure;
use Illuminate\Support\Str;

/**
 * RequestContext
 *
 * The per-request identity and correlation facts — who is calling, from where,
 * and under which request id — resolved ONCE at the transport boundary and
 * injected wherever they are needed.
 *
 * WHAT THIS CLOSED (GAP-5, GAP-6, C1):
 *
 * - AuditLogService imported Illuminate\Support\Facades\Request and called
 *   Request::ip() / Request::userAgent() directly. That service is invoked by
 *   AuthService, UserService, RoleService and EVERY model via HasAuditLog, so a
 *   rule the standard states plainly — a service never reaches for the request —
 *   was broken on the single most widely-called service in the app. Rows written
 *   from a console command or a queued job silently got NULL for both columns,
 *   and the service could not be unit-tested without an HTTP context.
 *
 * - There was no request id at all (`grep` for request_id/correlation returned
 *   nothing), so an audit row could not be tied to a log line, and identity was
 *   re-fetched from a global in each layer: $request->user() in controllers,
 *   Auth::id() in AuditLogService.
 *
 * WHY userId IS RESOLVED THROUGH A CLOSURE and not read here: this object is
 * built in stage 1 (global transport), but identity is not known until stage 3
 * (auth:sanctum). Rather than let Support reach for the Auth facade — which
 * would just relocate the problem this class exists to fix — the middleware,
 * which legitimately holds the request, hands over a resolver. The value is
 * memoized on first read, so the guard is consulted at most once per request.
 *
 * WHY IT IS MUTABLE: it is a per-request scratchpad bound as a SCOPED instance
 * (not a singleton — a queue worker clears scoped instances between jobs and
 * nothing else, so a singleton would hand every job in a worker process the same
 * correlation id), and
 * the two halves of it become knowable at two different stages. hydrate() is
 * called exactly once, by SetRequestContext. Outside HTTP — a console command,
 * a queued job, a test — the container's default gives a usable context with a
 * generated request id and null transport facts, which is the honest answer
 * there rather than a silent null-everything.
 */
final class RequestContext
{
    private ?Closure $userResolver = null;

    private bool $userResolved = false;

    private ?int $userId = null;

    public function __construct(
        private string $requestId = '',
        private ?string $ip = null,
        private ?string $userAgent = null,
    ) {
        if ($this->requestId === '') {
            $this->requestId = (string) Str::uuid();
        }
    }

    /**
     * Populate the transport facts. Called once, from SetRequestContext.
     */
    public function hydrate(string $requestId, ?string $ip, ?string $userAgent): void
    {
        $this->requestId = $requestId;
        $this->ip        = $ip;
        $this->userAgent = $userAgent;
    }

    /**
     * Supply the "who is calling" lookup. Deferred because identity resolves
     * two stages after this object is built.
     */
    public function resolveUserUsing(Closure $resolver): void
    {
        $this->userResolver = $resolver;
        $this->userResolved = false;
        $this->userId       = null;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function ip(): ?string
    {
        return $this->ip;
    }

    public function userAgent(): ?string
    {
        return $this->userAgent;
    }

    /**
     * The authenticated user's id, or null outside an authenticated request.
     * Memoized: the resolver runs at most once.
     */
    public function userId(): ?int
    {
        if ($this->userResolved) {
            return $this->userId;
        }

        $this->userResolved = true;
        $this->userId       = $this->userResolver === null
            ? null
            : ($this->userResolver)();

        return $this->userId;
    }
}
