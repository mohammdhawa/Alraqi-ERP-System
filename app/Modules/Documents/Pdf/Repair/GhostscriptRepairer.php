<?php

declare(strict_types=1);

namespace App\Modules\Documents\Pdf\Repair;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Ghostscript re-renders the file as PDF 1.4, whose classic cross-reference
 * table FPDI's free parser reads. The alternative to qpdf where only
 * Ghostscript is installed (`DOCUMENTS_PDF_REPAIR=ghostscript`).
 */
final class GhostscriptRepairer implements PdfRepairer
{
    public function __construct(
        private readonly string $binary,
        private readonly int $timeout,
    ) {}

    public function repair(string $source, string $target): bool
    {
        try {
            $result = Process::timeout($this->timeout)->run([
                $this->binary, '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '-sDEVICE=pdfwrite', '-dCompatibilityLevel=1.4', '-sOutputFile='.$target, $source,
            ]);
        } catch (Throwable $e) {
            Log::warning('documents: ghostscript could not run', ['binary' => $this->binary, 'error' => $e->getMessage()]);

            return false;
        }

        if ($result->exitCode() === 0) {
            return true;
        }

        Log::warning('documents: ghostscript failed', ['binary' => $this->binary, 'exit' => $result->exitCode(), 'error' => mb_substr($result->errorOutput(), 0, 500)]);

        return false;
    }
}
