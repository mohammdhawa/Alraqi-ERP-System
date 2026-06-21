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
|   - permission:departments.view  declares the required permission now;
|                  CheckPermission is non-breaking until the RBAC package
|                  (Package D) implements User::hasPermission(), at which
|                  point enforcement turns on without touching this file.
|
*/

Route::middleware(['auth:sanctum', 'audit', 'permission:departments.view']) // permission wired now, enforced after Package D
    ->name('departments.')
    ->group(function () {
        Route::get('/', [DepartmentController::class, 'index'])->name('index');
        Route::post('/', [DepartmentController::class, 'store'])->name('store');
        Route::get('/{department}', [DepartmentController::class, 'show'])->name('show');
        Route::match(['put', 'patch'], '/{department}', [DepartmentController::class, 'update'])->name('update');
        Route::delete('/{department}', [DepartmentController::class, 'destroy'])->name('destroy');
    });
