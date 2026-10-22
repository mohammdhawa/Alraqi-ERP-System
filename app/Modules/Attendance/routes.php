<?php

declare(strict_types=1);

use App\Modules\Attendance\Controllers\AttendanceRecordController;
use App\Modules\Attendance\Controllers\AttendanceReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Attendance Module Routes — oversight (work order §4.5, slice 2)
|--------------------------------------------------------------------------
|
| Loaded by ModuleServiceProvider under /api/attendance. HR's and managers'
| side; the employee's own attendance is under /api/me/attendance.
|
| AUTHORIZATION: the verb is `permission:` — attendance.records.view /
| .correct / .reject, each its own grant; the row is `can:` on the bound
| record (AttendanceRecordPolicy), scoped by the employee's CURRENT unit
| (GAP-3, D6). `can:` runs after route-model binding, so an unknown id is a
| 404 and an out-of-reach one a 403, before any body is validated.
|
*/

// The reports (slice 2b). Reads of other people's attendance, so the same
// permission as the records list, and the same reach. Literal paths, so no
// `{record}` route can swallow them.
Route::middleware(['auth:sanctum', 'audit', 'permission:attendance.records.view'])
    ->name('attendance.')
    ->group(function () {
        Route::get('/report', [AttendanceReportController::class, 'daily'])->name('report.daily');
        Route::get('/report/monthly', [AttendanceReportController::class, 'monthly'])->name('report.monthly');
        Route::get('/report/employee', [AttendanceReportController::class, 'employee'])->name('report.employee');
        Route::get('/absent-today', [AttendanceReportController::class, 'absent'])->name('absent-today');
    });

Route::middleware(['auth:sanctum', 'audit'])
    ->prefix('records')
    ->name('attendance.records.')
    ->group(function () {
        Route::get('/', [AttendanceRecordController::class, 'index'])
            ->middleware('permission:attendance.records.view')
            ->name('index');

        Route::get('/{record}/selfie', [AttendanceRecordController::class, 'selfie'])
            ->middleware(['permission:attendance.records.view', 'can:view,record'])
            ->whereNumber('record')
            ->name('selfie');

        Route::patch('/{record}/checkout', [AttendanceRecordController::class, 'correctCheckout'])
            ->middleware(['permission:attendance.records.correct', 'can:correct,record'])
            ->whereNumber('record')
            ->name('correct-checkout');

        Route::patch('/{record}/reject', [AttendanceRecordController::class, 'reject'])
            ->middleware(['permission:attendance.records.reject', 'can:reject,record'])
            ->whereNumber('record')
            ->name('reject');

        Route::delete('/{record}/reject', [AttendanceRecordController::class, 'unreject'])
            ->middleware(['permission:attendance.records.reject', 'can:reject,record'])
            ->whereNumber('record')
            ->name('unreject');
    });
