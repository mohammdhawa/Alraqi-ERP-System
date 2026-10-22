<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Where attendance selfies live: the PRIVATE `local` disk (Q11; mapping
 * finding 5). Legacy kept them on the public disk, where anyone holding or
 * guessing a URL could open a dated photo of a face at a GPS position. Here no
 * URL exists; the authorised endpoint (slice 2) streams the file.
 *
 * The path is attendance/selfies/{employee}/{Y-m-d}/{uuid}.{ext}, as legacy's,
 * and the extension comes from the image's content, not the client's file
 * name — falling back to jpg, as legacy does.
 */
final class SelfieStore
{
    public const DISK = 'local';

    public function store(UploadedFile $file, int $employeeId, CarbonInterface $moment): string
    {
        $directory = config('attendance.selfie_directory', 'attendance/selfies')."/{$employeeId}/{$moment->toDateString()}";

        return $file->storeAs($directory, Str::uuid().'.'.($file->extension() ?: 'jpg'), self::DISK);
    }

    public function delete(?string $path): void
    {
        if ($path !== null) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
