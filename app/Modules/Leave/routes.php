<?php

declare(strict_types=1);

use App\Modules\Leave\Controllers\LeaveBalanceController;
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
