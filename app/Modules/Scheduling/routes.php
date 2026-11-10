<?php

declare(strict_types=1);

use App\Modules\Scheduling\Controllers\BulkAssignmentController;
use App\Modules\Scheduling\Controllers\ScheduleCalendarController;
use App\Modules\Scheduling\Controllers\ShiftController;
use App\Modules\Scheduling\Controllers\ShiftSwapController;
use App\Modules\Scheduling\Controllers\WorkScheduleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Scheduling routes — prefixed /api/scheduling by ModuleServiceProvider
|--------------------------------------------------------------------------
|
| Work shifts (docs/superpowers/specs/2026-11-09-work-shifts-backend-core-design.md §9.2).
|
| AUTHORIZATION: `scheduling.view` reads, `scheduling.manage` writes. Both
| are company-only (decision round S12: HR only): neither is on
| PermissionReach's allow-list, so a scoped grant never confers them.
|
| A person's own assignments live under /api/hr/employees/{employee}/work-schedules
| (HR's routes), beside their postings.
|
*/

Route::middleware(['auth:sanctum', 'audit'])->name('scheduling.')->group(function () {
    Route::prefix('shifts')->name('shifts.')->group(function () {
        Route::get('/', [ShiftController::class, 'index'])->middleware('permission:scheduling.view')->name('index');
        Route::post('/', [ShiftController::class, 'store'])->middleware('permission:scheduling.manage')->name('store');
        Route::get('/{shift}', [ShiftController::class, 'show'])->middleware('permission:scheduling.view')->whereNumber('shift')->name('show');
        Route::patch('/{shift}', [ShiftController::class, 'update'])->middleware('permission:scheduling.manage')->whereNumber('shift')->name('update');
        Route::delete('/{shift}', [ShiftController::class, 'destroy'])->middleware('permission:scheduling.manage')->whereNumber('shift')->name('destroy');
    });

    Route::prefix('schedules')->name('schedules.')->group(function () {
        Route::get('/', [WorkScheduleController::class, 'index'])->middleware('permission:scheduling.view')->name('index');
        Route::post('/', [WorkScheduleController::class, 'store'])->middleware('permission:scheduling.manage')->name('store');
        Route::get('/{schedule}', [WorkScheduleController::class, 'show'])->middleware('permission:scheduling.view')->whereNumber('schedule')->name('show');
        Route::patch('/{schedule}', [WorkScheduleController::class, 'update'])->middleware('permission:scheduling.manage')->whereNumber('schedule')->name('update');
        Route::delete('/{schedule}', [WorkScheduleController::class, 'destroy'])->middleware('permission:scheduling.manage')->whereNumber('schedule')->name('destroy');
    });

    Route::post('assignments/bulk', BulkAssignmentController::class)->middleware('permission:scheduling.manage')->name('assignments.bulk');

    Route::get('calendar', ScheduleCalendarController::class)->middleware('permission:scheduling.view')->name('calendar');

    // Duty exchanges (piece 2, spec §6.4): HR reads every one, and cancels an
    // approved one before it starts. The manager decides through /api/approvals.
    Route::prefix('swaps')->name('swaps.')->group(function () {
        Route::get('/', [ShiftSwapController::class, 'index'])->middleware('permission:scheduling.view')->name('index');
        Route::get('/{swap}', [ShiftSwapController::class, 'show'])->middleware('permission:scheduling.view')->whereNumber('swap')->name('show');
        Route::post('/{swap}/cancel', [ShiftSwapController::class, 'cancel'])->middleware('permission:scheduling.manage')->whereNumber('swap')->name('cancel');
    });
});
