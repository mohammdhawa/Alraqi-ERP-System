<?php

declare(strict_types=1);

use App\Modules\HR\Controllers\EmployeeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| HR Module Routes
|--------------------------------------------------------------------------
|
| The mere existence of this file activates the HR module: ModuleServiceProvider
| auto-discovers it and loads it with:
|   - Prefix:     /api/hr   (the lowercased module folder name)
|   - Middleware: api
|
| The module prefix (hr) differs from the resource name (employees), so a
| standard apiResource maps cleanly to /api/hr/employees with no doubling.
|
| MIDDLEWARE:
|   - auth:sanctum  every endpoint requires an authenticated user.
|   - audit         records a request-level trail for each action.
|   - permission:hr.employees.{action}  each route is guarded by the permission
|                  matching its action (view/create/update/delete), now enforced
|                  by RBAC (Package D). The apiResource was expanded into explicit
|                  routes so read actions (index/show) require .view while writes
|                  require their own create/update/delete permission — a read-only
|                  role cannot mutate data.
|
*/

Route::middleware(['auth:sanctum', 'audit'])
    ->name('employees.')
    ->group(function () {
        Route::get('employees', [EmployeeController::class, 'index'])
            ->middleware('permission:hr.employees.view')->name('index');
        Route::post('employees', [EmployeeController::class, 'store'])
            ->middleware('permission:hr.employees.create')->name('store');
        Route::get('employees/{employee}', [EmployeeController::class, 'show'])
            ->middleware('permission:hr.employees.view')->name('show');
        Route::match(['put', 'patch'], 'employees/{employee}', [EmployeeController::class, 'update'])
            ->middleware('permission:hr.employees.update')->name('update');
        Route::delete('employees/{employee}', [EmployeeController::class, 'destroy'])
            ->middleware('permission:hr.employees.delete')->name('destroy');
    });
