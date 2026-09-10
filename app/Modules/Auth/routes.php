<?php

declare(strict_types=1);

use App\Modules\Auth\Controllers\AuthController;
use App\Modules\Auth\Controllers\NotificationController;
use App\Modules\Auth\Controllers\RoleController;
use App\Modules\Auth\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth Module Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by ModuleServiceProvider with:
|   - Prefix: /api/auth
|   - Middleware: api
|
| ROUTE DESIGN:
|   - Public routes (login, refresh): no auth middleware.
|   - Protected routes (logout, me): auth:sanctum middleware.
|   - Rate limiting on login/refresh to prevent brute force.
|
| RATE LIMITING (all three limiters are registered in AppServiceProvider::boot):
|   - Login:   5/min, keyed on IP **and** email. Keying on IP alone lets one
|              attacker behind a company NAT lock out everyone sharing that
|              address, which turns the protection into the outage.
|   - Refresh: 10/min (higher, for legitimate rapid refreshes).
|   - Everything else: the baseline `api` limiter at 60/min, applied by
|              $middleware->throttleApi() in bootstrap/app.php.
|
| That last line was previously untrue in a way this comment helped hide: the
| `api` group carried no throttle at all, so every route except login and refresh
| was unlimited.
|
*/

// --- Public routes (no Sanctum guard) ---
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:auth-login')
    ->name('auth.login');

Route::post('/refresh', [AuthController::class, 'refresh'])
    ->middleware('throttle:auth-refresh')
    ->name('auth.refresh');

// --- Protected routes (Sanctum guard) ---
// The 'audit' middleware records a request-level trail for every
// authenticated action. Login/refresh are audited at the action level
// inside AuthService instead, since they run before authentication.
Route::middleware(['auth:sanctum', 'audit'])->group(function () {
    // AUTHORIZATION MODEL for these two: SELF-SCOPED (C7). They carry no
    // `permission:` because they act on the caller's own session and profile
    // and on nothing else — logout revokes only the caller's tokens, /me reads
    // only the caller's record. There is no permission that could sensibly gate
    // them: a user who may not read their own profile has no reason to hold a
    // token at all. C7 requires a permission OR a stated model; this is the
    // stated model, and it must stay stated — an unguarded route with no
    // comment is indistinguishable from one where the permission was forgotten.
    Route::post('/logout', [AuthController::class, 'logout'])
        ->name('auth.logout');

    Route::get('/me', [AuthController::class, 'me'])
        ->name('auth.me');

    // --- RBAC administration ---
    // Full role CRUD plus assigning a role to a user.
    Route::get('/roles', [RoleController::class, 'index'])
        ->middleware('permission:auth.roles.view')
        ->name('auth.roles.index');

    Route::post('/roles', [RoleController::class, 'store'])
        ->middleware('permission:auth.roles.create')
        ->name('auth.roles.store');

    // Assign/unassign are declared before the /{role} routes so the literal
    // paths win over the {role} wildcard.
    Route::post('/roles/assign', [RoleController::class, 'assign'])
        ->middleware('permission:auth.roles.update')
        ->name('auth.roles.assign');

    Route::post('/roles/unassign', [RoleController::class, 'unassign'])
        ->middleware('permission:auth.roles.update')
        ->name('auth.roles.unassign');

    Route::match(['put', 'patch'], '/roles/{role}', [RoleController::class, 'update'])
        ->middleware('permission:auth.roles.update')
        ->name('auth.roles.update');

    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])
        ->middleware('permission:auth.roles.delete')
        ->name('auth.roles.destroy');

    // --- User administration ---
    // Full user CRUD. Role grants are handled by the /roles/assign endpoints,
    // not here, so RBAC changes have a single source of truth.
    Route::get('/users', [UserController::class, 'index'])
        ->middleware('permission:auth.users.view')
        ->name('auth.users.index');

    Route::post('/users', [UserController::class, 'store'])
        ->middleware('permission:auth.users.create')
        ->name('auth.users.store');

    Route::match(['put', 'patch'], '/users/{user}', [UserController::class, 'update'])
        ->middleware('permission:auth.users.update')
        ->name('auth.users.update');

    Route::delete('/users/{user}', [UserController::class, 'destroy'])
        ->middleware('permission:auth.users.delete')
        ->name('auth.users.destroy');
});

// --- Notifications ---
// AUTHORIZATION MODEL: owner-scoped (C7). Each user reads and manages only their
// own notifications; the scoping is enforced in NotificationService by resolving
// every row through $user->notifications(), so no permission middleware applies.
//
// AUDITING IS PER-ROUTE, NOT PER-GROUP. The two READ endpoints are excluded from
// the 'audit' middleware because clients poll them constantly and they would
// flood the trail with no information in it. That exclusion was previously
// applied to the whole group, which silently swept up the one route here that
// CHANGES STATE — marking a notification read left no trace anywhere, since the
// Notification model also carries no HasAuditLog (deliberately, so the bulk
// fan-out insert stays event-free). C13 has no carve-out for cheap writes, and
// "it sits next to two polled endpoints" is not one.
Route::middleware('auth:sanctum')
    ->prefix('notifications')
    ->name('auth.notifications.')
    ->group(function () {
        // Polled reads — deliberately unaudited.
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('/unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');

        // A write: audited like every other state change.
        // whereNumber keeps a non-numeric id from reaching the controller's int
        // parameter, so a junk segment is a 404 from routing rather than a cast.
        Route::post('/{notification}/read', [NotificationController::class, 'markRead'])
            ->middleware('audit')
            ->whereNumber('notification')
            ->name('read');
    });
