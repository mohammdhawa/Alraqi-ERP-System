<?php

declare(strict_types=1);

namespace App\Modules\Documents\Pdf\Repair;

/**
 * Rewrites a PDF the printing cannot open into one it can (documents design
 * §11.5). Bound in AppServiceProvider from `documents.pdf.repair.driver`.
 */
interface PdfRepairer
{
    /**
     * Write a rewritten copy of $source to $target. True when the tool says
     * it succeeded; the caller still checks that the result opens. Never
     * throws: a missing or failing tool is false.
     */
    public function repair(string $source, string $target): bool;
}
