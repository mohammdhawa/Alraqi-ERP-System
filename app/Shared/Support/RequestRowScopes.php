<?php

declare(strict_types=1);

namespace App\Shared\Support;

use App\Modules\Auth\Models\User;
use App\Shared\Services\AccessScope;
use Illuminate\Http\Request;

/**
 * RequestRowScopes — one resolved `RowScope` per permission, per REQUEST.
 *
 * WHY THIS EXISTS: a field gate is a per-ROW question once grants carry scopes.
 * "May this caller see pay?" used to be `hasPermission('hr.employees.viewSalary')`
 * — one yes or no for the whole response. Since GAP-3 the honest question is
 * "does the caller's viewSalary grant reach THIS employee's unit?", asked for
 * every row on a page. Resolving the scope per row would walk the tree once per
 * row; this resolves it once and every row reads the same answer.
 *
 * WHY THE REQUEST, NOT A SINGLETON: the request is the one object whose lifetime
 * is exactly one HTTP request everywhere — including the test suite, where the
 * application (and every scoped binding in it) survives from one `getJson()` to
 * the next. A container-held memo would carry a scope across requests there and
 * report a revoked grant, or a manager already moved, as still in force.
 *
 * WARM IT BEFORE RENDERING. `warm()` resolves the scopes up front so the first
 * lookup from inside a Resource is a memo read, not the tree walk — the Resource
 * contract forbids queries. A caller that forgets gets the SAME answer, only
 * resolved later; correctness never depends on the warm-up, only the layer the
 * queries land in does.
 *
 * ⚠️ THE MEMO BELONGS TO THE REQUEST OBJECT IT WAS WRITTEN ON. A FormRequest is
 * a copy of the base request with its OWN attribute bag, while a Resource is
 * rendered against the base one. Everything in a request — policies, form
 * requests, the controller's warm-up, the Resource — passes `request()`, so a
 * permission's scope is walked once per request, not once per caller.
 */
final class RequestRowScopes
{
    private const PREFIX = 'row-scope:';

    /**
     * The scope for a user who may not be the one making the request — the
     * form a Gate policy is handed.
     *
     * The memo is keyed by permission alone, so it may only answer for the
     * request's own user. `Gate::forUser($someoneElse)` resolves fresh rather
     * than reading — or poisoning — the caller's memo.
     */
    public static function forUser(User $user, string $permission): RowScope
    {
        $request = request();

        if ($request->user()?->is($user) === true) {
            return self::for($request, $permission);
        }

        return app(AccessScope::class)->for($user, $permission);
    }

    public static function for(Request $request, string $permission): RowScope
    {
        $key = self::PREFIX.$permission;
        $scope = $request->attributes->get($key);

        if ($scope instanceof RowScope) {
            return $scope;
        }

        $user = $request->user();

        // FAILS CLOSED: no authenticated user reaches nothing.
        $scope = $user === null ? RowScope::units([]) : app(AccessScope::class)->for($user, $permission);

        $request->attributes->set($key, $scope);

        return $scope;
    }

    /**
     * @param  array<int, string>  $permissions
     */
    public static function warm(Request $request, array $permissions): void
    {
        foreach ($permissions as $permission) {
            self::for($request, $permission);
        }
    }
}
