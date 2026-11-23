<?php

declare(strict_types=1);

use App\Modules\Documents\Controllers\DocumentCommentController;
use App\Modules\Documents\Controllers\DocumentController;
use App\Modules\Documents\Controllers\DocumentTemplateController;
use App\Modules\Documents\Controllers\LetterheadController;
use App\Modules\Documents\Controllers\TemplateCategoryController;
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
        // إرسال مستند للاعتماد — a unit's manager (the service decides). The
        // `/{document}` routes to come take `whereNumber`, so `/stamps` and
        // `/letterhead` stay theirs.
        Route::post('/', [DocumentController::class, 'store'])->name('store');

        // الصناديق — incoming, outgoing, and all (`documents.view`, which the
        // controller checks so its refusal has its own words).
        Route::get('/', [DocumentController::class, 'index'])->name('index');

        // مستندات تحتاج إلى تدخل — the pending documents no one can move as
        // they stand (spec §7.8). `documents.close` is a Gate ability only the
        // super-admin bypass passes, never a permission.
        Route::get('/attention', [DocumentController::class, 'attention'])
            ->middleware('can:documents.close')
            ->name('attention');

        // القوالب وتصنيفاتها — reading is for any signed-in account (the
        // controller shows a manager more); writing needs the templates'
        // permission. Declared before `/{document}`, with their own prefixes.
        Route::get('/template-categories', [TemplateCategoryController::class, 'index'])->name('template-categories.index');
        Route::get('/templates', [DocumentTemplateController::class, 'index'])->name('templates.index');
        Route::get('/templates/{template}', [DocumentTemplateController::class, 'show'])
            ->whereNumber('template')->name('templates.show');

        Route::middleware('permission:documents.templates.manage')->group(function () {
            Route::post('/template-categories', [TemplateCategoryController::class, 'store'])->name('template-categories.store');
            Route::put('/template-categories/{category}', [TemplateCategoryController::class, 'update'])
                ->whereNumber('category')->name('template-categories.update');
            Route::delete('/template-categories/{category}', [TemplateCategoryController::class, 'destroy'])
                ->whereNumber('category')->name('template-categories.destroy');

            Route::post('/templates', [DocumentTemplateController::class, 'store'])->name('templates.store');
            // المعاينة — the page printed with sample values; nothing is saved.
            Route::post('/templates/preview', [DocumentTemplateController::class, 'preview'])->name('templates.preview');
            Route::put('/templates/{template}', [DocumentTemplateController::class, 'update'])
                ->whereNumber('template')->name('templates.update');
            Route::delete('/templates/{template}', [DocumentTemplateController::class, 'destroy'])
                ->whereNumber('template')->name('templates.destroy');
        });

        // العرض — the creator, a step holder or `documents.view`; a deleted
        // document still binds, to answer 410 to those who may see it.
        Route::get('/{document}', [DocumentController::class, 'show'])
            ->whereNumber('document')
            ->withTrashed()
            ->middleware('can:view,document')
            ->name('show');
        // الحذف — the creator or the administrator, before any signature; a
        // deleted document still binds, to answer 410.
        Route::delete('/{document}', [DocumentController::class, 'destroy'])
            ->whereNumber('document')
            ->withTrashed()
            ->middleware('can:delete,document')
            ->name('destroy');
        Route::get('/{document}/file', [DocumentController::class, 'file'])
            ->whereNumber('document')
            ->withTrashed()
            ->middleware('can:view,document')
            ->name('file');

        // التعليقات والمرفقات — any party reads and writes the thread; a deleted
        // document answers 410 to them. A file is bound scoped to its document,
        // so another document's is a 404.
        Route::get('/{document}/comments', [DocumentCommentController::class, 'index'])
            ->whereNumber('document')
            ->withTrashed()
            ->middleware('can:view,document')
            ->name('comments.index');
        Route::post('/{document}/comments', [DocumentCommentController::class, 'store'])
            ->whereNumber('document')
            ->withTrashed()
            ->middleware('can:view,document')
            ->name('comments.store');
        Route::get('/{document}/attachments/{attachment}', [DocumentController::class, 'attachment'])
            ->whereNumber(['document', 'attachment'])
            ->withTrashed()
            ->scopeBindings()
            ->middleware('can:view,document')
            ->name('attachment');

        // القرار — the caller's own current step (the service decides whose
        // turn it is); anyone off the document is refused before the body is
        // read.
        Route::post('/{document}/decision', [DocumentController::class, 'decide'])
            ->whereNumber('document')
            ->withTrashed()
            ->middleware('can:view,document')
            ->name('decision');

        // «لم يُستكمل» — the system administrator closes a pending document
        // (spec §7.8); a deleted one still binds, to answer 410.
        Route::post('/{document}/close', [DocumentController::class, 'close'])
            ->whereNumber('document')
            ->withTrashed()
            ->middleware('can:documents.close')
            ->name('close');

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
