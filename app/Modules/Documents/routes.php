<?php

declare(strict_types=1);

use App\Modules\Documents\Controllers\LetterheadController;
use App\Modules\Documents\Controllers\UnitStampController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Documents Module Routes
|--------------------------------------------------------------------------
|
| Loaded by ModuleServiceProvider under /api/documents
| (docs/superpowers/specs/2026-11-22-documents-design.md §16). The
| employee's own signature is under /api/me, and the administrator's view of
| one under /api/hr/employees/{employee}, in those modules' route files.
|
*/

Route::middleware(['auth:sanctum', 'audit'])
    ->name('documents.')
    ->group(function () {
        // أختام الوحدات — the system administrator alone (D23.1).
        // `documents.stamps.manage` is a Gate ability only the super-admin
        // bypass passes, never a permission. A deleted unit is a 404 at
        // binding.
        Route::middleware('can:documents.stamps.manage')
            ->prefix('stamps')
            ->name('stamps.')
            ->group(function () {
                Route::get('/', [UnitStampController::class, 'index'])->name('index');
                Route::post('/{department}', [UnitStampController::class, 'store'])
                    ->whereNumber('department')->name('store');
                Route::get('/{department}/image', [UnitStampController::class, 'image'])
                    ->whereNumber('department')->name('image');
            });

        // الترويسة — the company's header and footer images (design §8.2),
        // with the templates' permission.
        Route::middleware('permission:documents.templates.manage')
            ->prefix('letterhead')
            ->name('letterhead.')
            ->group(function () {
                Route::get('/', [LetterheadController::class, 'show'])->name('show');
                Route::post('/{part}', [LetterheadController::class, 'store'])
                    ->whereIn('part', ['header', 'footer'])->name('store');
                Route::get('/{part}/image', [LetterheadController::class, 'image'])
                    ->whereIn('part', ['header', 'footer'])->name('image');
                Route::delete('/{part}', [LetterheadController::class, 'destroy'])
                    ->whereIn('part', ['header', 'footer'])->name('destroy');
            });
    });
