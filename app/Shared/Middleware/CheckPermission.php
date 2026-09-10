<?php

declare(strict_types=1);

namespace App\Shared\Middleware;

use App\Shared\Traits\ApiErrors;
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
    // The envelope is the trait's, not this class's. §3's layer contract says a
    // middleware may reject early with a status code but must never hand-build
    // JSON — this used to do exactly that, twice, duplicating the envelope
    // outside ApiRespond (C16).
    use ApiErrors;

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        // C9: 401 and 403 are different answers. This arm is reachable only if a
        // route carries permission: without auth:sanctum in front of it, which
        // would be a routing mistake — but it fails closed rather than assuming.
        if (! $user) {
            return $this->unauthorized();
        }

        // RBAC is enforced: the authenticated user is the Auth module's User
        // model (the auth provider is bound to it — see IdentityModelTest), which
        // always exposes hasPermission(). A super admin bypasses inside that
        // method. There is deliberately NO fail-open fallback: a user who lacks
        // the permission is denied, full stop.
        if (! $user->hasPermission($permission)) {
            return $this->forbidden('ليس لديك الصلاحيات الكافية لتنفيذ هذا الإجراء.');
        }

        return $next($request);
    }
}
