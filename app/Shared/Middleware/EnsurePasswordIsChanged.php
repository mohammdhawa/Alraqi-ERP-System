<?php

declare(strict_types=1);

namespace App\Shared\Middleware;

use App\Modules\Auth\Exceptions\PasswordChangeRequiredException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsurePasswordIsChanged — the forced first-login change, enforced by the API.
 *
 * WHY THIS IS SERVER-SIDE. The account model ratified 2026-09-22 issues every
 * employee a TEMPORARY password, handed to them by HR. That password is, by
 * construction, a secret more than one person knows. Attendance is recorded
 * through the app, so the very first thing such a credential can do is sign
 * somebody's attendance — the fact payroll is computed from.
 *
 * If the rule lived only in the Flutter screen it would be a suggestion: any
 * client that skips the screen, and any direct HTTP call, would sail past it.
 * Here it is a property of the API.
 *
 * REGISTERED ON THE `api` GROUP, GLOBALLY, not per route — so a module added
 * next year is covered without its author having to know this exists. That is
 * also why the allow-list is by ROUTE NAME rather than by path prefix: a name
 * is stable across a URL change, and an unnamed route can never accidentally
 * match one of these three.
 *
 * ⚠️ THE GUARD IS NAMED EXPLICITLY. `$request->user()` reads the DEFAULT guard,
 * which is only switched to sanctum once `auth:sanctum` has run — and this
 * middleware's position relative to that is decided by Laravel's priority
 * sorting, not by where it is registered. Reading the default guard would make
 * the check silently pass whenever the ordering put it first, which is the
 * failure mode where a security middleware looks present and enforces nothing.
 * Naming the guard makes the result independent of the order.
 *
 * An unauthenticated request resolves to null and passes straight through:
 * login and refresh must stay reachable, and refresh in particular is how a
 * client with an expired access token gets back to the password endpoint.
 */
class EnsurePasswordIsChanged
{
    /**
     * The only routes reachable while the temporary password is still in place.
     *
     * - `auth.password.change` resolves the situation. It is the only one that
     *   clears the flag.
     * - `auth.logout` ends the session. Refusing to let someone sign out of an
     *   account they cannot use would be absurd.
     * - `auth.me` is what the client reads to discover the state it is in —
     *   including `must_change_password` itself, which is how the app knows to
     *   open the change screen rather than the dashboard.
     *
     * @var array<int, string>
     */
    private const ALLOWED_ROUTES = [
        'auth.password.change',
        'auth.logout',
        'auth.me',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum');

        if ($user?->must_change_password === true
            && ! in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)
        ) {
            throw new PasswordChangeRequiredException;
        }

        return $next($request);
    }
}
