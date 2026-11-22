<?php

declare(strict_types=1);

namespace App\Modules\Documents\Controllers;

use App\Modules\Documents\Enums\LetterheadPart;
use App\Modules\Documents\Exceptions\NoLetterheadImageException;
use App\Modules\Documents\Requests\LetterheadImageRequest;
use App\Modules\Documents\Services\Letterhead;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The letterhead page of the dashboard (documents design §8.2, §15.2), behind
 * `permission:documents.templates.manage`.
 */
class LetterheadController extends Controller
{
    use ApiRespond;

    public function __construct(private readonly Letterhead $letterhead) {}

    /**
     * GET /api/documents/letterhead
     */
    public function show(): JsonResponse
    {
        $letterhead = $this->letterhead->current();

        $part = static fn (LetterheadPart $part): ?array => $letterhead->{$part->pathColumn()} === null ? null : [
            'uploaded_at' => $letterhead->{$part->uploadedAtColumn()}?->toIso8601String(),
        ];

        return $this->success([
            'header' => $part(LetterheadPart::Header),
            'footer' => $part(LetterheadPart::Footer),
        ]);
    }

    /**
     * POST /api/documents/letterhead/{part}
     */
    public function store(LetterheadImageRequest $request, string $part): JsonResponse
    {
        $part = LetterheadPart::from($part);
        $letterhead = $this->letterhead->replace($part, $request->file('image'), $request->user());

        return $this->created(
            data: ['uploaded_at' => $letterhead->{$part->uploadedAtColumn()}?->toIso8601String()],
            message: 'تم حفظ الترويسة.',
        );
    }

    /**
     * GET /api/documents/letterhead/{part}/image
     */
    public function image(string $part): StreamedResponse
    {
        $path = $this->letterhead->pathOf(LetterheadPart::from($part));

        if ($path === null || ! Storage::disk(Letterhead::DISK)->exists($path)) {
            throw new NoLetterheadImageException;
        }

        return Storage::disk(Letterhead::DISK)->response($path, headers: [
            'Content-Type'  => str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * DELETE /api/documents/letterhead/{part}
     */
    public function destroy(Request $request, string $part): JsonResponse
    {
        $this->letterhead->remove(LetterheadPart::from($part), $request->user());

        return $this->success(message: 'تم حذف الترويسة.');
    }
}
