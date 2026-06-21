<?php

declare(strict_types=1);

use App\Modules\Auth\Controllers\AuthController;
use App\Modules\Auth\Controllers\NotificationController;
use App\Modules\Auth\Controllers\RoleController;
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
    // Read + assign only; full role CRUD is deferred to seeders for now.
    Route::get('/roles', [RoleController::class, 'index'])
        ->middleware('permission:auth.roles.view')
        ->name('auth.roles.index');

    Route::post('/roles/assign', [RoleController::class, 'assign'])
        ->middleware('permission:auth.roles.update')
        ->name('auth.roles.assign');
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