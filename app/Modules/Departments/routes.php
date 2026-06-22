<?php

declare(strict_types=1);

use App\Modules\Departments\Controllers\DepartmentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Departments Module Routes
|--------------------------------------------------------------------------
|
| The mere existence of this file is what activates the Departments module:
| ModuleServiceProvider auto-discovers it and loads it with:
|   - Prefix:     /api/departments   (the lowercased module folder name)
|   - Middleware: api
|
| Because the module prefix is ALREADY `departments`, the resource is
| registered at the module root rather than via apiResource('departments'),
| which would double the segment into /api/departments/departments. The
| explicit routes below keep the standard `departments.*` route names while
| exposing the clean REST surface:
|
|   GET    /api/departments              departments.index
|   POST   /api/departments              departments.store
|   GET    /api/departments/{department} departments.show
|   PUT    /api/departments/{department} departments.update
|   DELETE /api/departments/{department} departments.destroy
|
| MIDDLEWARE:
|   - auth:sanctum  every endpoint requires an authenticated user.
|   - audit         records a request-level trail for each action.
|   - permission:departments.{action}  each route is guarded by the permission
|                  matching its action (view/create/update/delete), now enforced
|                  by RBAC (Package D). Read routes (index/show) require .view;
|                  writes require their own create/update/delete permission so a
|                  read-only role cannot mutate data.
|
*/

Route::middleware(['auth:sanctum', 'audit'])
    ->name('departments.')
    ->group(function () {
        Route::get('/', [DepartmentController::class, 'index'])
            ->middleware('permission:departments.view')->name('index');
        Route::post('/', [DepartmentController::class, 'store'])
            ->middleware('permission:departments.create')->name('store');
        Route::get('/{department}', [DepartmentController::class, 'show'])
            ->middleware('permission:departments.view')->name('show');
        Route::match(['put', 'patch'], '/{department}', [DepartmentController::class, 'update'])
            ->middleware('permission:departments.update')->name('update');
        Route::delete('/{department}', [DepartmentController::class, 'destroy'])
            ->middleware('permission:departments.delete')->name('destroy');
    });
