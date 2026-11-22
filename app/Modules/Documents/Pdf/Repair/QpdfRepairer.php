<?php

declare(strict_types=1);

namespace App\Modules\Documents\Pdf\Repair;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * qpdf without object streams writes a classic cross-reference table, which
 * FPDI's free parser reads. Exit 0 is success and 3 is "succeeded with
 * warnings"; anything else, or a tool that cannot be started, is a failure.
 */
final class QpdfRepairer implements PdfRepairer
{
    public function __construct(
        private readonly string $binary,
        private readonly int $timeout,
    ) {}

    public function repair(string $source, string $target): bool
    {
        try {
            $result = Process::timeout($this->timeout)->run([$this->binary, '--object-streams=disable', $source, $target]);
        } catch (Throwable $e) {
            Log::warning('documents: qpdf could not run', ['binary' => $this->binary, 'error' => $e->getMessage()]);

            return false;
        }

        if (in_array($result->exitCode(), [0, 3], true)) {
            return true;
        }

        Log::warning('documents: qpdf failed', ['binary' => $this->binary, 'exit' => $result->exitCode(), 'error' => mb_substr($result->errorOutput(), 0, 500)]);

        return false;
    }
}
