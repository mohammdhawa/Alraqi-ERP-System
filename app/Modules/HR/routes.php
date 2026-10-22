<?php

declare(strict_types=1);

use App\Modules\HR\Controllers\EmployeeAssignmentController;
use App\Modules\HR\Controllers\EmployeeController;
use App\Modules\HR\Controllers\EmployeeWarningController;
use App\Modules\HR\Controllers\JobTitleController;
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
| The module prefix (hr) differs from every resource name, so the resources are
| registered at the module root with no doubling.
|
| MIDDLEWARE — every route carries all three:
|   - auth:sanctum  no endpoint here is public.
|   - audit         a request-level trail for each action.
|   - permission:…  the permission matching the action. Reads take `.view`;
|                   each write takes its OWN create/update/delete, so a
|                   read-only role cannot mutate anything (C8). apiResource is
|                   deliberately NOT used: it collapses all five routes onto one
|                   middleware list, which is exactly how a write ends up
|                   guarded by `.view`.
|
| ROW SCOPE (GAP-3) — every route addressing ONE employee, or a warning (which
|   belongs to one), also carries `can:{ability},{param}`: EmployeePolicy or
|   EmployeeWarningPolicy decides whether that row lies inside the caller's
|   scope for the same verb, and answers 403 with an Arabic message if not
|   (START_HERE §9 decision 19). `permission:` is listed first so a caller who
|   lacks the verb gets the verb refusal, not the scope one. The index is not
|   refused but FILTERED (EmployeeController::index), and the unit a person is
|   being placed into is checked after validation (EmployeeRequest,
|   EmployeeAssignmentRequest). Job titles are not row-scoped: a catalogue has
|   no owning unit.
|
| ROUTE ORDER: literal segments are declared before wildcards, or the wildcard
| swallows them. `job-titles` and `warnings` are declared ahead of
| `employees/{employee}`-shaped paths for that reason, even though the current
| segment counts happen not to collide — the habit is what survives the next
| route somebody adds.
|
| NESTING: assignments and warnings are read and created UNDER an employee,
| because neither has meaning apart from a person and there is no company-wide
| listing of either. Amending a warning is NOT nested — the row's employee is
| already fixed by its FK, and a nested edit URL invites two ids that disagree.
|
*/

Route::middleware(['auth:sanctum', 'audit'])->group(function () {

    // ── دليل المسميات الوظيفية — the governed catalogue ──────────────────
    Route::name('job-titles.')->group(function () {
        Route::get('job-titles', [JobTitleController::class, 'index'])
            ->middleware('permission:hr.job_titles.view')->name('index');
        Route::post('job-titles', [JobTitleController::class, 'store'])
            ->middleware('permission:hr.job_titles.create')->name('store');
        Route::get('job-titles/{jobTitle}', [JobTitleController::class, 'show'])
            ->middleware('permission:hr.job_titles.view')->name('show');
        Route::match(['put', 'patch'], 'job-titles/{jobTitle}', [JobTitleController::class, 'update'])
            ->middleware('permission:hr.job_titles.update')->name('update');
        Route::delete('job-titles/{jobTitle}', [JobTitleController::class, 'destroy'])
            ->middleware('permission:hr.job_titles.delete')->name('destroy');
    });

    // ── الإنذارات — amendments, addressed by the warning's own id ─────────
    Route::name('warnings.')->group(function () {
        Route::match(['put', 'patch'], 'warnings/{warning}', [EmployeeWarningController::class, 'update'])
            ->middleware(['permission:hr.warnings.update', 'can:update,warning'])->name('update');
        Route::delete('warnings/{warning}', [EmployeeWarningController::class, 'destroy'])
            ->middleware(['permission:hr.warnings.delete', 'can:delete,warning'])->name('destroy');
    });

    // ── الموظفون ─────────────────────────────────────────────────────────
    Route::name('employees.')->group(function () {
        Route::get('employees', [EmployeeController::class, 'index'])
            ->middleware('permission:hr.employees.view')->name('index');
        Route::post('employees', [EmployeeController::class, 'store'])
            ->middleware('permission:hr.employees.create')->name('store');

        // سجل التعيين — read and append only. There is no update or delete
        // route, and no permission that could authorize one: a posting
        // recorded wrongly is corrected by recording the correction.
        Route::get('employees/{employee}/assignments', [EmployeeAssignmentController::class, 'index'])
            ->middleware(['permission:hr.assignments.view', 'can:viewAssignments,employee'])->name('assignments.index');
        Route::post('employees/{employee}/assignments', [EmployeeAssignmentController::class, 'store'])
            ->middleware(['permission:hr.assignments.create', 'can:recordAssignment,employee'])->name('assignments.store');

        Route::get('employees/{employee}/warnings', [EmployeeWarningController::class, 'index'])
            ->middleware(['permission:hr.warnings.view', 'can:viewWarnings,employee'])->name('warnings.index');
        Route::post('employees/{employee}/warnings', [EmployeeWarningController::class, 'store'])
            ->middleware(['permission:hr.warnings.create', 'can:createWarning,employee'])->name('warnings.store');

        Route::get('employees/{employee}', [EmployeeController::class, 'show'])
            ->middleware(['permission:hr.employees.view', 'can:view,employee'])->name('show');
        Route::match(['put', 'patch'], 'employees/{employee}', [EmployeeController::class, 'update'])
            ->middleware(['permission:hr.employees.update', 'can:update,employee'])->name('update');
        Route::delete('employees/{employee}', [EmployeeController::class, 'destroy'])
            ->middleware(['permission:hr.employees.delete', 'can:delete,employee'])->name('destroy');
    });
});
