<?php

declare(strict_types=1);

namespace App\Modules\Me\Controllers;

use App\Modules\Me\Requests\MyPhotoRequest;
use App\Modules\Me\Services\ProfilePhotos;
use App\Modules\Me\Services\SelfEmployee;
use App\Shared\Traits\ApiRespond;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The caller's own photo (work order §4.8): upload, replacing the old one,
 * and read it back as a stream.
 */
class MyPhotoController extends Controller
{
    use ApiRespond;

    public function __construct(
        private readonly SelfEmployee $self,
        private readonly ProfilePhotos $photos,
    ) {}

    /**
     * POST /api/me/photo — multipart, `photo`.
     */
    public function store(MyPhotoRequest $request): JsonResponse
    {
        $photo = $this->photos->replace($this->self->of($request->user()), $request->file('photo'), $request->user());

        return $this->created(
            data: ['uploaded_at' => $photo->created_at?->toIso8601String()],
            message: 'تم حفظ الصورة الشخصية.',
        );
    }

    /**
     * GET /api/me/photo — the file, never a URL. Not cached by anything
     * between.
     */
    public function show(Request $request): StreamedResponse
    {
        return Storage::disk(ProfilePhotos::DISK)->response($this->photos->pathOf($this->self->of($request->user())), headers: [
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
