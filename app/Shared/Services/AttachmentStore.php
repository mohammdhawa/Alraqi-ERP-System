<?php

declare(strict_types=1);

namespace App\Shared\Services;

use App\Modules\Auth\Models\User;
use App\Shared\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Writes and streams attachments (LEAVE_BUILD.md §5) — the selfie store's
 * rules, for any record: the private disk, a name the server chose, never a
 * URL.
 *
 * IT DECIDES NOTHING ABOUT WHO. Reading is the subject's policy, checked by
 * the route before `stream()` runs.
 *
 * A FILE IS NOT IN THE TRANSACTION. A caller that stores files inside one and
 * then fails must `deleteFiles()` what it stored, so a refused action leaves
 * nothing on disk — the database row rolls back by itself, the file does not.
 */
class AttachmentStore
{
    public const DISK = 'local';

    public function store(UploadedFile $file, Model $attachable, User $by): Attachment
    {
        $directory = 'attachments/'.$attachable->getMorphClass().'/'.$attachable->getKey();
        $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension() ?: 'bin');
        $path = $file->storeAs($directory, Str::uuid().'.'.$extension, self::DISK);

        return Attachment::create([
            'attachable_type' => $attachable->getMorphClass(),
            'attachable_id'   => $attachable->getKey(),
            'disk'            => self::DISK,
            'path'            => $path,
            'original_name'   => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type'       => (string) $file->getMimeType(),
            'size'            => (int) $file->getSize(),
            'uploaded_by'     => $by->getKey(),
        ]);
    }

    /**
     * @param  array<int, string>  $paths
     */
    public function deleteFiles(array $paths): void
    {
        if ($paths !== []) {
            Storage::disk(self::DISK)->delete($paths);
        }
    }

    public function stream(Attachment $attachment): StreamedResponse
    {
        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->original_name, [
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
