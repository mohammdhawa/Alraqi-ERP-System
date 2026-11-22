<?php

declare(strict_types=1);

namespace App\Modules\Documents\Pdf;

use App\Modules\Documents\Enums\IntakeOutcome;

/**
 * An upload the printing can work on. Both paths are inside the PdfSession
 * that checked it, so the caller copies what it keeps before the session ends.
 */
final class AcceptedPdf
{
    public function __construct(
        /** The PDF every later print starts from. */
        public readonly string $printablePath,
        /** The file as uploaded, set only when it had to be rewritten. */
        public readonly ?string $uploadedPath,
        public readonly int $pageCount,
        public readonly IntakeOutcome $outcome,
    ) {}
}
