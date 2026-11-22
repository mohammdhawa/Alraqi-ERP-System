<?php

declare(strict_types=1);

namespace App\Modules\Documents\Pdf\Repair;

/**
 * `DOCUMENTS_PDF_REPAIR=none`: no tool on the server, so every PDF the
 * printing cannot open is refused at upload.
 */
final class NullRepairer implements PdfRepairer
{
    public function repair(string $source, string $target): bool
    {
        return false;
    }
}
