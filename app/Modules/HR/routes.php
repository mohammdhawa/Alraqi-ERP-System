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
|   - permission:hr.employees.view  declares the required permission now;
|                  CheckPermission is non-breaking until the RBAC package
|                  (Package D) implements User::hasPermission(), at which
|                  point enforcement turns on without touching this file.
|
*/

Route::middleware(['auth:sanctum', 'audit'])->group(function () {
    Route::apiResource('employees', EmployeeController::class)
        ->middleware('permission:hr.employees.view'); // wired now, enforced after Package D
});
