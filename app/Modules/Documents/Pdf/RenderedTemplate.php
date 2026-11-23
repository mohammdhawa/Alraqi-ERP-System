<?php

declare(strict_types=1);

namespace App\Modules\Documents\Pdf;

/**
 * A template printed by TemplatePrinter: the PDF, a file in the print's
 * session (gone when the session ends, so the caller copies it out first),
 * and where the cards must stop above the footer image (28 mm when one was
 * printed, otherwise null: piece 2's 6 mm).
 */
final readonly class RenderedTemplate
{
    public function __construct(
        public string $path,
        public ?int $cardFloor,
    ) {}
}
