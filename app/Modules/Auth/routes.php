<?php

declare(strict_types=1);

use App\Modules\Auth\Controllers\AuthController;
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
});