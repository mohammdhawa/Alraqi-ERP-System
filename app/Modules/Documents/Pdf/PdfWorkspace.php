<?php

declare(strict_types=1);

namespace App\Modules\Documents\Pdf;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Runs one print in a folder of its own on the private disk, and deletes the
 * folder when the print ends, success or failure (documents design §11.3).
 *
 * Anything a print must keep (the printed PDF, the original) is copied out to
 * its storage by the caller before the callback returns.
 */
final class PdfWorkspace
{
    public const DISK = 'local';

    public const ROOT = 'pdf-work';

    /**
     * @template T
     *
     * @param  callable(PdfSession): T  $work
     * @return T
     */
    public function run(callable $work): mixed
    {
        $relative = self::ROOT.'/'.Str::uuid();
        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory($relative);

        try {
            return $work(new PdfSession($disk->path($relative)));
        } finally {
            $disk->deleteDirectory($relative);
        }
    }
}
