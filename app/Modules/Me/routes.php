<?php

declare(strict_types=1);

use App\Modules\Approvals\Controllers\MyApprovalsController;
use App\Modules\Attendance\Controllers\MyAttendanceController;
use App\Modules\Leave\Controllers\LeaveBalanceController;
use App\Modules\Leave\Controllers\MyLeaveController;
use App\Modules\Me\Controllers\DeviceTokenController;
use App\Modules\Me\Controllers\MyPhotoController;
use App\Modules\Me\Controllers\MyProfileController;
use App\Modules\Me\Controllers\MySummaryController;
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
    // The caller's own employee record (work order §4.8), with their own pay
    // block and national id by the self rule — EmployeeResource::forSelf(),
    // never a viewSalary grant (requirements §6.1).
    Route::get('/profile', [MyProfileController::class, 'show'])
        ->name('me.profile');

    // The caller's own photo (§4.8; no photo is imported, 2026-10-14). A new
    // upload replaces the old one; nobody approves it (2026-10-19). Streamed,
    // never a URL.
    Route::post('/photo', [MyPhotoController::class, 'store'])
        ->name('me.photo.store');

    Route::get('/photo', [MyPhotoController::class, 'show'])
        ->name('me.photo.show');

    // The home screen (§4.8, requirements §6.2): one object of cards, each
    // null until its module can answer — today only salary (Stage 4).
    Route::get('/summary', [MySummaryController::class, 'show'])
        ->name('me.summary');

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

    // The caller's own selfie — streamed, never a URL (Q11). The id is looked
    // up among the caller's own records only: a colleague's is a 404.
    Route::get('/attendance/{record}/selfie', [MyAttendanceController::class, 'selfie'])
        ->whereNumber('record')
        ->name('me.attendance.selfie');

    // The approver's inbox (work order §4.6, design §7): the steps waiting
    // on the caller's employee now, or with ?status=decided what they
    // decided. The app's and the dashboard's (2026-10-14).
    Route::get('/approvals', [MyApprovalsController::class, 'index'])
        ->name('me.approvals.index');

    // Leave (work order §4.7). The caller's own balance for a year.
    Route::get('/leave-balance', [LeaveBalanceController::class, 'mine'])
        ->name('me.leave-balance');

    // The caller's own leave, with HR's excuses for them.
    Route::get('/leave-requests', [MyLeaveController::class, 'index'])
        ->name('me.leave-requests.index');

    // Filing — the chain is resolved, never chosen (Q1). Multipart, for the
    // files a sick leave needs.
    Route::post('/leave-requests', [MyLeaveController::class, 'store'])
        ->name('me.leave-requests.store');

    // Withdrawing — the engine's, before any decision (§9 Q1 = B). Looked up
    // among the caller's own leave only.
    Route::post('/leave-requests/{leaveRequest}/cancel', [MyLeaveController::class, 'cancel'])
        ->whereNumber('leaveRequest')
        ->name('me.leave-requests.cancel');
});
