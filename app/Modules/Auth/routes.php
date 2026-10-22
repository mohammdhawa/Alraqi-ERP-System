<?php

declare(strict_types=1);

use App\Modules\Auth\Controllers\AuditLogController;
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

    // Sessions (work order §4.3) — self-scoped like logout: each acts on the
    // caller's own sessions and on nothing else. Another account's session id
    // is a 404 (AuthService::revokeSession).
    Route::post('/logout-all', [AuthController::class, 'logoutAll'])
        ->name('auth.logout-all');

    Route::get('/sessions', [AuthController::class, 'sessions'])
        ->name('auth.sessions.index');

    Route::delete('/sessions/{session}', [AuthController::class, 'revokeSession'])
        ->whereUuid('session')
        ->name('auth.sessions.destroy');

    Route::get('/me', [AuthController::class, 'me'])
        ->name('auth.me');

    // Replacing your OWN password — self-scoped for the same reason as the two
    // above, and for one more: this is the route that clears
    // `must_change_password`, so a permission gating it would be a permission
    // every employee must hold before they can use the system at all. A grant
    // that everyone must have is not an authorization decision.
    //
    // Audited (it is in the group): a credential change is exactly the kind of
    // state change C13 exists for, and AuditLogService::sanitize() already
    // redacts `password`, so the trail records that it happened without the
    // value.
    Route::post('/password', [AuthController::class, 'changePassword'])
        ->name('auth.password.change');

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
    //
    // ⚙️ `auth.roles.assign`, NOT `auth.roles.update`, since 2026-09-26 (GAP-15,
    // decision 22). The two used to be one permission, which meant one grant
    // did two very different things: assigning is bounded by the assigner's
    // own reach (RoleService::ensureMayGrant); editing a role's DEFINITION
    // changes what every holder of that role can do, company-wide. Split,
    // `auth.roles.update` is company-only (PermissionReach) and
    // `auth.roles.assign` keeps its scope.
    //
    // ⚙️ Safe to grant over a unit since 2026-09-28 (GAP-16, decision 24): an
    // assigner hands out, and takes away, only the permissions they hold over
    // the same units, and super_admin only a super admin.
    Route::post('/roles/assign', [RoleController::class, 'assign'])
        ->middleware('permission:auth.roles.assign')
        ->name('auth.roles.assign');

    Route::post('/roles/unassign', [RoleController::class, 'unassign'])
        ->middleware('permission:auth.roles.assign')
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

    // --- Audit trail (سجل التدقيق) ---
    // READ-ONLY, AND PERMANENTLY SO. There is no store/update/destroy here and
    // there must never be one: audit_logs is append-only by design, and no
    // permission exists that could authorize a mutation.
    //
    // Both routes carry `auth.audit.view`, so the detail endpoint cannot be
    // reached by a caller who may not see the list it came from. The BEFORE/
    // AFTER VALUES are gated separately by `auth.audit.viewValues`, which is
    // field-level and appears on no route — AuditLogDetailResource checks it
    // (C11), for the reason its docblock gives: the classification axes are not
    // redacted at write time, so the trail would otherwise be a way around
    // hr.employees.viewSalary.
    //
    // These stay inside the `audit` group deliberately. Reading the trail is a
    // security-relevant action and belongs in it; the notifications carve-out
    // below covers polled reads, and this endpoint must not be polled.
    //
    // The literal path is declared before the wildcard, and whereNumber keeps a
    // non-numeric segment a routing 404 rather than a cast inside the binding.
    Route::get('/audit-logs', [AuditLogController::class, 'index'])
        ->middleware('permission:auth.audit.view')
        ->name('auth.audit-logs.index');

    Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show'])
        ->middleware('permission:auth.audit.view')
        ->whereNumber('auditLog')
        ->name('auth.audit-logs.show');
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

        // A write too, so audited. Legacy's `notifications/read-all`
        // (mapping §8, work order §4.3).
        Route::post('/read-all', [NotificationController::class, 'markAllRead'])
            ->middleware('audit')
            ->name('read-all');
    });
