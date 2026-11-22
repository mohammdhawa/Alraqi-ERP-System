<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Signatures and stamps at rest (documents design §10, I.9): on the private
 * disk, encrypted with the app key, under a name the server chose. They are
 * decrypted only in memory, to stream to their owner or to print.
 *
 * IT DECIDES NOTHING ABOUT WHO. Callers check that first.
 */
final class SealedImages
{
    public const DISK = 'local';

    public function put(string $bytes, string $directory): string
    {
        $path = 'sealed/'.trim($directory, '/').'/'.Str::uuid().'.bin';

        Storage::disk(self::DISK)->put($path, Crypt::encrypt($bytes, false));

        return $path;
    }

    public function get(string $path): string
    {
        return Crypt::decrypt((string) Storage::disk(self::DISK)->get($path), false);
    }

    public function delete(string $path): void
    {
        Storage::disk(self::DISK)->delete($path);
    }
}
