<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use App\Modules\Auth\Exceptions\AuthenticationException;
use App\Shared\Middleware\AuditLogMiddleware;
use App\Shared\Middleware\CheckPermission;

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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Centralized rendering for auth failures. Covers the base
        // AuthenticationException and its subclasses (AccountDisabled,
        // InvalidRefreshToken), each carrying its own HTTP status code.
        // Keeps controllers thin and ensures no exception class, trace, or
        // internal message leaks to API clients.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], $e->statusCode);
            }
        });
    })->create();
