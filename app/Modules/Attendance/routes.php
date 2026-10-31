<?php

declare(strict_types=1);

use App\Modules\Attendance\Controllers\AttendanceLocationReviewController;
use App\Modules\Attendance\Controllers\AttendanceRecordController;
use App\Modules\Attendance\Controllers\AttendanceReportController;
use App\Modules\Attendance\Controllers\WorkSiteAssignmentController;
use App\Modules\Attendance\Controllers\WorkSiteController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'audit'])
    ->name('attendance.work-sites.')
    ->group(function () {
        Route::get('/work-sites', [WorkSiteController::class, 'index'])->middleware('permission:attendance.work_sites.view')->name('index');
        Route::post('/work-sites', [WorkSiteController::class, 'store'])->middleware('permission:attendance.work_sites.manage')->name('store');
        Route::patch('/work-sites/{site}', [WorkSiteController::class, 'update'])->middleware('permission:attendance.work_sites.manage')->whereNumber('site')->name('update');
        Route::patch('/work-sites/{site}/archive', [WorkSiteController::class, 'archive'])->middleware('permission:attendance.work_sites.manage')->whereNumber('site')->name('archive');
        Route::get('/work-site-assignments', [WorkSiteAssignmentController::class, 'index'])->middleware('permission:attendance.work_sites.view')->name('assignments.index');
        Route::post('/work-site-assignments', [WorkSiteAssignmentController::class, 'store'])->middleware('permission:attendance.work_sites.manage')->name('assignments.store');
        Route::patch('/work-site-assignments/{assignment}', [WorkSiteAssignmentController::class, 'update'])->middleware('permission:attendance.work_sites.manage')->whereNumber('assignment')->name('assignments.update');
    });

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

        Route::post('/location-review/approve', [AttendanceLocationReviewController::class, 'bulk'])
            ->middleware('permission:attendance.records.approveLocation')
            ->name('location-review.bulk-approve');

        Route::patch('/{record}/location-review/approve', [AttendanceLocationReviewController::class, 'approve'])
            ->middleware(['permission:attendance.records.approveLocation', 'can:approveLocation,record'])
            ->whereNumber('record')
            ->name('location-review.approve');

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
