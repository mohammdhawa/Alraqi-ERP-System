<?php

declare(strict_types=1);

use App\Modules\Leave\Controllers\LeaveBalanceController;
use App\Modules\Leave\Controllers\LeaveExcuseController;
use App\Modules\Leave\Controllers\LeaveRequestController;
use App\Modules\Leave\Controllers\LeaveTypeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Leave Module Routes (work order §4.7, docs/LEAVE_BUILD.md)
|--------------------------------------------------------------------------
|
| Loaded by ModuleServiceProvider under /api/leave. HR's side, and the
| catalogue every leave form reads; the employee's own leave is under
| /api/me.
|
| AUTHORIZATION: reading the active types needs no permission — the
| employee's leave form needs them (a catalogue read). Writing them is
| `leave.types.create` / `.update` / `.delete`, company-only grants: a
| catalogue, as departments and job titles.
|
| Balances are `leave.balances.view` / `.update`, row-scoped through the
| employee's CURRENT unit (D6): the list on the builder, the update by
| LeaveReach before anything is written.
|
| Reading one leave and its files is `can:view` (LeaveRequestPolicy): the
| employee, anyone on its chain, or `leave.requests.view` within reach. A file
| is bound scoped to its leave.
|
| HR's excuses are `leave.excuses.create` / `.delete`, row-scoped through the
| employee's current unit, as balances.
|
*/

Route::middleware(['auth:sanctum', 'audit'])
    ->prefix('types')
    ->name('leave.types.')
    ->group(function () {
        Route::get('/', [LeaveTypeController::class, 'index'])->name('index');

        Route::post('/', [LeaveTypeController::class, 'store'])
            ->middleware('permission:leave.types.create')
            ->name('store');

        Route::patch('/{leaveType}', [LeaveTypeController::class, 'update'])
            ->middleware('permission:leave.types.update')
            ->whereNumber('leaveType')
            ->name('update');

        Route::delete('/{leaveType}', [LeaveTypeController::class, 'destroy'])
            ->middleware('permission:leave.types.delete')
            ->whereNumber('leaveType')
            ->name('destroy');
    });

Route::middleware(['auth:sanctum', 'audit'])
    ->prefix('balances')
    ->name('leave.balances.')
    ->group(function () {
        Route::get('/', [LeaveBalanceController::class, 'index'])
            ->middleware('permission:leave.balances.view')
            ->name('index');

        Route::put('/{employee}/{year}', [LeaveBalanceController::class, 'update'])
            ->middleware('permission:leave.balances.update')
            ->whereNumber('employee')
            ->where('year', '20[0-9]{2}|2100')
            ->name('update');
    });

// HR's register: every leave in reach over a window (legacy's
// /leave-register), row-scoped on the builder.
Route::middleware(['auth:sanctum', 'audit', 'permission:leave.requests.view'])
    ->get('/requests', [LeaveRequestController::class, 'index'])
    ->name('leave.requests.index');

Route::middleware(['auth:sanctum', 'audit'])
    ->prefix('requests/{leaveRequest}')
    ->whereNumber(['leaveRequest', 'attachment'])
    ->scopeBindings()
    ->name('leave.requests.')
    ->group(function () {
        Route::get('/', [LeaveRequestController::class, 'show'])
            ->middleware('can:view,leaveRequest')
            ->name('show');

        Route::get('/attachments/{attachment}', [LeaveRequestController::class, 'attachment'])
            ->middleware('can:view,leaveRequest')
            ->name('attachment');

        // The employee came back early: HR cuts the leave short (legacy).
        Route::post('/return-to-work', [LeaveRequestController::class, 'returnToWork'])
            ->middleware(['permission:leave.requests.returnToWork', 'can:returnToWork,leaveRequest'])
            ->name('return-to-work');
    });

Route::middleware(['auth:sanctum', 'audit'])
    ->prefix('excuses')
    ->name('leave.excuses.')
    ->group(function () {
        Route::post('/', [LeaveExcuseController::class, 'store'])
            ->middleware('permission:leave.excuses.create')
            ->name('store');

        Route::delete('/{leaveRequest}', [LeaveExcuseController::class, 'destroy'])
            ->middleware(['permission:leave.excuses.delete', 'can:cancelExcuse,leaveRequest'])
            ->whereNumber('leaveRequest')
            ->name('destroy');
    });
