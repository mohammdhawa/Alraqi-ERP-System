<?php

declare(strict_types=1);

namespace App\Modules\Documents\Controllers;

use App\Modules\Documents\Exceptions\NoSignatureException;
use App\Modules\Documents\Requests\MySignatureRequest;
use App\Modules\Documents\Services\Signatures;
use App\Modules\Me\Services\SelfEmployee;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The caller's own signature (documents design §10.1): drawn once, then only
 * seen. There is no delete or replace route for its owner (D15).
 */
class MySignatureController extends Controller
{
    use ApiRespond;

    public function __construct(
        private readonly SelfEmployee $self,
        private readonly Signatures $signatures,
    ) {}

    /**
     * POST /api/me/signature
     */
    public function store(MySignatureRequest $request): JsonResponse
    {
        $signature = $this->signatures->draw(
            $this->self->of($request->user()),
            $request->file('signature'),
            $request->source(),
            $request->user(),
        );

        return $this->created(
            data: ['drawn_at' => $signature->created_at?->toIso8601String()],
            message: 'تم حفظ توقيعك.',
        );
    }

    /**
     * GET /api/me/signature — the owner's own image, decrypted in memory.
     */
    public function show(Request $request): StreamedResponse
    {
        $signature = $this->signatures->current($this->self->of($request->user())) ?? throw new NoSignatureException;
        $bytes = $this->signatures->bytes($signature);

        return response()->stream(static function () use ($bytes): void {
            echo $bytes;
        }, 200, [
            'Content-Type'  => 'image/png',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
