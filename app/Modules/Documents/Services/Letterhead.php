<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Documents\Enums\LetterheadPart;
use App\Modules\Documents\Exceptions\NoLetterheadImageException;
use App\Modules\Documents\Models\DocumentLetterhead;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The company's letterhead (documents design §8.2): a header and a footer
 * image on the private disk, which piece 3's printing frames template pages
 * with. One row, made on first use.
 *
 * A replaced or removed image's file is deleted once the row's change has
 * committed, as ProfilePhotos does: a document printed earlier carries its
 * letterhead inside its own PDF.
 */
final class Letterhead
{
    public const DISK = 'local';

    public const DIRECTORY = 'documents/letterhead';

    public function current(): DocumentLetterhead
    {
        return DocumentLetterhead::query()->orderBy('id')->first() ?? DocumentLetterhead::create([]);
    }

    public function replace(LetterheadPart $part, UploadedFile $image, User $by): DocumentLetterhead
    {
        $extension = $image->getMimeType() === 'image/png' ? 'png' : 'jpg';
        $path = $image->storeAs(self::DIRECTORY, Str::uuid().'.'.$extension, self::DISK);

        try {
            [$letterhead, $old] = DB::transaction(function () use ($part, $by, $path): array {
                $letterhead = DocumentLetterhead::query()->lockForUpdate()->find($this->current()->getKey());
                $old = $letterhead->{$part->pathColumn()};

                $letterhead->update([
                    $part->pathColumn()       => $path,
                    $part->uploadedAtColumn() => now(),
                    'updated_by'              => $by->getKey(),
                ]);

                return [$letterhead, $old];
            });
        } catch (Throwable $e) {
            Storage::disk(self::DISK)->delete((string) $path);

            throw $e;
        }

        if ($old !== null) {
            Storage::disk(self::DISK)->delete($old);
        }

        return $letterhead;
    }

    /**
     * @throws NoLetterheadImageException
     */
    public function remove(LetterheadPart $part, User $by): DocumentLetterhead
    {
        [$letterhead, $old] = DB::transaction(function () use ($part, $by): array {
            $letterhead = DocumentLetterhead::query()->lockForUpdate()->find($this->current()->getKey());
            $old = $letterhead->{$part->pathColumn()} ?? throw new NoLetterheadImageException;

            $letterhead->update([
                $part->pathColumn()       => null,
                $part->uploadedAtColumn() => null,
                'updated_by'              => $by->getKey(),
            ]);

            return [$letterhead, $old];
        });

        Storage::disk(self::DISK)->delete($old);

        return $letterhead;
    }

    /**
     * The image's path on the local disk, or null when none is uploaded.
     */
    public function pathOf(LetterheadPart $part): ?string
    {
        return DocumentLetterhead::query()->orderBy('id')->value($part->pathColumn());
    }
}
