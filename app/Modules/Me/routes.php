<?php

declare(strict_types=1);

use App\Modules\Attendance\Controllers\MyAttendanceController;
use App\Modules\Me\Controllers\DeviceTokenController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Me Module Routes — the caller's own surface
|--------------------------------------------------------------------------
|
| Loaded by ModuleServiceProvider under /api/me. This is the employee app's
| self-service surface (EMPLOYEE_APP_REQUIREMENTS.md §6, work order §4.8):
| profile, summary, attendance, leave and approvals land here as they are
| built. Device tokens are the first resident (work order §4.3).
|
| AUTHORIZATION MODEL: SELF-SCOPED (C7), for every route in this file. None
| carries a `permission:` middleware, because each acts only on the caller's
| own records and resolves them from the authenticated user, never from an id
| in the request. A route here that took somebody else's id would be a defect.
|
| MIDDLEWARE:
|   - auth:sanctum  every endpoint requires an authenticated user.
|   - audit         records a request-level trail for each action.
|
*/

Route::middleware(['auth:sanctum', 'audit'])->group(function () {
    // Push tokens. The body carries the token rather than the path: FCM
    // tokens are long and not guaranteed URL-safe, and legacy's client already
    // sends it this way.
    Route::post('/device-tokens', [DeviceTokenController::class, 'store'])
        ->name('me.device-tokens.store');

    Route::delete('/device-tokens', [DeviceTokenController::class, 'destroy'])
        ->name('me.device-tokens.destroy');

    // Attendance (work order §4.5). The logic is the Attendance module's; the
    // routes are here because they are the caller's own. No permission and no
    // flag: every active employee with an account records (Q7, 2026-10-16).
    Route::get('/attendance', [MyAttendanceController::class, 'index'])
        ->name('me.attendance.index');

    Route::post('/attendance', [MyAttendanceController::class, 'store'])
        ->name('me.attendance.store');

    Route::post('/attendance/sync', [MyAttendanceController::class, 'sync'])
        ->name('me.attendance.sync');
});
