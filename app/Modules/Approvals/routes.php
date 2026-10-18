<?php

declare(strict_types=1);

use App\Modules\Approvals\Controllers\ApprovalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Approvals Module Routes — the request screen (design §7)
|--------------------------------------------------------------------------
|
| Loaded by ModuleServiceProvider under /api/approvals. The approver's inbox
| is the caller's own, so it is under /api/me/approvals.
|
| AUTHORIZATION: approving is NOT a permission (mapping §6) — you act on a
| step because you are its approver, which the engine checks. Every route is
| behind `can:view` (ApprovalPolicy): the requester, anyone on the chain, or
| `approvals.view` within reach. Reassigning is the one verb that is a grant:
| `permission:approvals.reassign`, and `can:reassign` for its reach (§9 Q4).
|
| `can:` runs after route-model binding, so an unknown id is a 404 and an
| unreachable one a 403, before any body is validated. Steps are bound
| SCOPED to their approval: a step of another request is a 404.
|
*/

Route::middleware(['auth:sanctum', 'audit'])
    ->prefix('{approval}')
    ->whereNumber(['approval', 'step'])
    ->scopeBindings()
    ->name('approvals.')
    ->group(function () {
        Route::get('/', [ApprovalController::class, 'show'])
            ->middleware('can:view,approval')
            ->name('show');

        Route::post('/steps/{step}/approve', [ApprovalController::class, 'approve'])
            ->middleware('can:view,approval')
            ->name('steps.approve');

        Route::post('/steps/{step}/reject', [ApprovalController::class, 'reject'])
            ->middleware('can:view,approval')
            ->name('steps.reject');

        Route::post('/withdraw', [ApprovalController::class, 'withdraw'])
            ->middleware('can:view,approval')
            ->name('withdraw');

        Route::patch('/steps/{step}/assignee', [ApprovalController::class, 'reassign'])
            ->middleware(['permission:approvals.reassign', 'can:reassign,approval'])
            ->name('steps.reassign');
    });
