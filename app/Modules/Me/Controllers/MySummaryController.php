<?php

declare(strict_types=1);

namespace App\Modules\Me\Controllers;

use App\Modules\Me\Services\MySummary;
use App\Modules\Me\Services\SelfEmployee;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * GET /api/me/summary — the home screen (work order §4.8): cards over every
 * module, each `null` until its module can answer.
 */
class MySummaryController extends Controller
{
    use ApiRespond;

    public function show(Request $request, SelfEmployee $self, MySummary $summary): JsonResponse
    {
        return $this->success(
            data: $summary->for($request->user(), $self->of($request->user())),
            message: 'تم جلب الملخص.',
        );
    }
}
