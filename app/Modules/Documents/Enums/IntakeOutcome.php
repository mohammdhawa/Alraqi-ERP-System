<?php

declare(strict_types=1);

namespace App\Modules\Documents\Enums;

/**
 * What the upload check made of a file (documents design §11.1, §11.5).
 *
 * - `AsUploaded` — a PDF the printing opens, kept byte for byte.
 * - `Repaired` — a PDF the printing could not open, rewritten on the server;
 *   the file as uploaded is kept beside it.
 * - `FromPhoto` — a JPG or PNG turned into a one-page A4 PDF.
 */
enum IntakeOutcome: int
{
    case AsUploaded = 1;
    case Repaired   = 2;
    case FromPhoto  = 3;
}
