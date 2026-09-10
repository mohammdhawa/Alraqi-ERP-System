<?php

use App\Shared\Exceptions\ApiExceptionRenderer;
use App\Shared\Middleware\AuditLogMiddleware;
use App\Shared\Middleware\CheckPermission;
use App\Shared\Middleware\SetRequestContext;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Register all custom aliases in a single call: a second alias()
        // call overwrites the first rather than merging, which previously
        // dropped the 'audit' alias entirely.
        $middleware->alias([
            'audit'      => AuditLogMiddleware::class,
            'permission' => CheckPermission::class,
        ]);

        // STAGE 1 (global transport). Laravel 12's default `api` group contains
        // only SubstituteBindings — ->throttleApi() was never called, so despite
        // the comment in ModuleServiceProvider claiming otherwise, 26 of 28
        // routes had NO rate limit at all (verified via route:list, 2026-09-08).
        // Only auth/login and auth/refresh were throttled, by their own named
        // limiters. The `api` limiter is registered in AppServiceProvider.
        $middleware->throttleApi();

        // RATE LIMITING MUST PRECEDE AUTHENTICATION.
        //
        // Adding throttleApi() alone was NOT enough, and the way it failed is
        // worth stating plainly: Laravel sorts a route's middleware by
        // $middlewarePriority, and the default list puts AuthenticatesRequests
        // BEFORE ThrottleRequests. So on every protected route the resolved
        // order was `auth:sanctum → throttle:api`, and an unauthenticated
        // request short-circuited at auth with a 401 without ever reaching the
        // limiter. Probed: 70 unauthenticated requests to /api/departments
        // returned 70 × 401 and zero 429s — the limiter existed and was
        // unreachable by exactly the traffic it is for. A credential-stuffing
        // or token-guessing run against any protected endpoint was unlimited.
        //
        // The list below is Laravel's default with ThrottleRequests (and its
        // Redis twin) moved above the auth entry. It is spelled out in full
        // rather than patched relatively because
        // Kernel::addToMiddlewarePriorityRelative() is a no-op when the
        // middleware is ALREADY in the list — which ThrottleRequests is — so
        // prependToPriorityList() would silently do nothing here.
        //
        // ⚠️ On a framework upgrade, re-check this against Laravel's
        // $middlewarePriority. AuthThrottleTest::test_unauthenticated_traffic_is_rate_limited
        // fails if the ordering regresses.
        $middleware->priority([
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            AuthenticatesRequests::class,
            AuthenticatesSessions::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);

        // Builds the per-request identity/correlation context and echoes
        // X-Request-Id on the way out.
        //
        // GLOBAL, not on the `api` group. Group middleware runs only on a
        // MATCHED route, so an unknown path or a wrong verb — the 404 and 405
        // that a confused client is most likely to report — resolved before it
        // and came back with no correlation id at all, contradicting the
        // "every response" contract this exists to provide. Global middleware
        // wraps routing itself, so those responses now carry one too.
        $middleware->prepend(SetRequestContext::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // THE single error-rendering site for the whole API (stage 8, C15/C16/C17).
        //
        // Typed on Throwable deliberately: one mapper covering every failure is
        // the point. ApiExceptionRenderer returns null for non-API, non-JSON
        // requests, so Laravel's normal HTML handling is untouched.
        //
        // Domain exceptions are matched by the App\Shared\Exceptions\DomainException
        // interface, not by class, so a new module's exceptions render correctly
        // without ever editing this file — the zero-config property that the old
        // self-rendering exceptions existed to protect.
        $exceptions->render(
            fn (Throwable $e, Request $request) => app(ApiExceptionRenderer::class)->render($e, $request),
        );
    })->create();
