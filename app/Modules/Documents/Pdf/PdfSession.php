<?php

declare(strict_types=1);

namespace App\Modules\Documents\Pdf;

use Mpdf\Mpdf;

/**
 * One print's folder, and the mPDF instances that write into it (documents
 * design §11.3). Made only by PdfWorkspace, which deletes the folder when the
 * print ends.
 *
 * mPDF keeps its font cache and the PNGs it decodes (a signature, a stamp,
 * their transparency masks) in its temp directory. Pointing that directory
 * here is what keeps a decrypted image from outliving the print. The font
 * cache is rebuilt for every print: a few documents a day can afford it.
 */
final class PdfSession
{
    public function __construct(public readonly string $directory) {}

    /**
     * @param  array<string, mixed>  $config  overrides of the defaults below
     */
    public function mpdf(array $config = []): Mpdf
    {
        return new Mpdf([
            'mode'             => 'utf-8',
            'format'           => 'A4',
            'default_font'     => 'dejavusans',
            'autoScriptToLang' => true,
            'autoLangToFont'   => true,
            ...$config,
            'tempDir'          => $this->directory,
        ]);
    }

    /**
     * An absolute path for a scratch file of this print.
     */
    public function path(string $name): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.$name;
    }
}
