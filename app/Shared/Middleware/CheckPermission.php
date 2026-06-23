<?php

declare(strict_types=1);

namespace App\Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CheckPermission Middleware
 *
 * Guards routes behind permission checks. Designed to work with a
 * future RBAC system (roles + permissions tables).
 *
 * Usage:
 *   Route::middleware('permission:hr.employees.view')->get('/employees', ...);
 *   Route::middleware('permission:finance.invoices.create')->post('/invoices', ...);
 *
 * WHY this exists now (before RBAC is built):
 * - The middleware slot is established in the architecture from day one.
 * - Other modules can declare their permission requirements immediately.
 * - When the RBAC module is built, only this middleware changes —
 *   all consuming routes remain untouched.
 *
 * CONVENTION for permission strings:
 *   {module}.{resource}.{action}
 *   e.g., hr.employees.view, finance.invoices.approve
 *
 * Register in bootstrap/app.php:
 *   ->withMiddleware(function (Middleware $middleware) {
 *       $middleware->alias(['permission' => CheckPermission::class]);
 *   })
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرّح بالوصول.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // TODO: Replace with actual permission check once RBAC module is built.
        // For now, we check if the user model has a `hasPermission` method.
        // If it doesn't (RBAC not yet implemented), we allow access.
        // This ensures the middleware is non-breaking during early development.
        if (method_exists($user, 'hasPermission') && ! $user->hasPermission($permission)) {
            return response()->json([
                'success' => false,
                'message' => 'ليس لديك الصلاحيات الكافية لتنفيذ هذا الإجراء.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}