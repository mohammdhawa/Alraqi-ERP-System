<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

/**
 * Is this file a WHOLE PNG or JPEG? (final review, 2026-11-22)
 *
 * `getimagesize()` reads only the header, so a cut-off upload passes it, and
 * fails later, when mPDF prints it: a signature its owner can never replace
 * (D15), a stamp on every card. This reads the whole file without decoding
 * it (a decode of a large image could exhaust memory):
 *
 * - PNG: every chunk is walked to IEND, its length within the file and its
 *   CRC right, so truncation and corruption are both caught;
 * - JPEG: it opens with SOI and ends with EOI (FF D9), trailing padding
 *   aside, so truncation is caught.
 */
final class CompleteImage
{
    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    /**
     * IMAGETYPE_PNG or IMAGETYPE_JPEG when the file is a whole image of one
     * of $types, otherwise null.
     *
     * @param  array<int, int>  $types
     */
    public static function typeOf(string $path, array $types = [IMAGETYPE_PNG, IMAGETYPE_JPEG]): ?int
    {
        $info = @getimagesize($path);

        if ($info === false || ! in_array($info[2], $types, true) || $info[0] < 1 || $info[1] < 1) {
            return null;
        }

        $bytes = @file_get_contents($path);

        if (! is_string($bytes)) {
            return null;
        }

        $whole = match ($info[2]) {
            IMAGETYPE_PNG  => self::wholePng($bytes),
            IMAGETYPE_JPEG => self::wholeJpeg($bytes),
            default        => false,
        };

        return $whole ? $info[2] : null;
    }

    private static function wholePng(string $bytes): bool
    {
        if (! str_starts_with($bytes, self::PNG_SIGNATURE)) {
            return false;
        }

        $length = strlen($bytes);
        $offset = strlen(self::PNG_SIGNATURE);

        while ($offset + 12 <= $length) {
            $size = unpack('N', substr($bytes, $offset, 4))[1];
            $type = substr($bytes, $offset + 4, 4);

            if ($offset + 12 + $size > $length) {
                return false;
            }

            $crc = unpack('N', substr($bytes, $offset + 8 + $size, 4))[1];

            if (crc32($type.substr($bytes, $offset + 8, $size)) !== $crc) {
                return false;
            }

            if ($type === 'IEND') {
                return true;
            }

            $offset += 12 + $size;
        }

        return false;
    }

    private static function wholeJpeg(string $bytes): bool
    {
        return str_starts_with($bytes, "\xFF\xD8")
            && str_ends_with(rtrim($bytes, "\x00"), "\xFF\xD9");
    }
}
