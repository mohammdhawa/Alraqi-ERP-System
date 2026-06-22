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
| RATE LIMITING:
|   - Login: 5 attempts per minute (prevents credential stuffing).
|   - Refresh: 10 attempts per minute (slightly higher for legitimate rapid refreshes).
|   - Protected routes use the default API throttle.
|
| Register the rate limiters in AppServiceProvider::boot():
|
|   RateLimiter::for('auth-login', function (Request $request) {
|       return Limit::perMinute(5)->by($request->ip());
|   });
|
|   RateLimiter::for('auth-refresh', function (Request $request) {
|       return Limit::perMinute(10)->by($request->ip());
|   });
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
// Each user reads/manages only their own notifications, so authorization is
// ownership (enforced in NotificationService) and no permission middleware is
// needed. Deliberately NOT in the 'audit' group: the list and unread-count
// endpoints are polled frequently and would flood the audit log.
Route::middleware('auth:sanctum')
    ->prefix('notifications')
    ->name('auth.notifications.')
    ->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('/unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');
        Route::post('/{notification}/read', [NotificationController::class, 'markRead'])->name('read');
    });