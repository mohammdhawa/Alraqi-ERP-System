<?php

declare(strict_types=1);

namespace App\Modules\Documents\Pdf;

/**
 * A template printed by TemplatePrinter: the PDF, a file in the print's
 * session (gone when the session ends, so the caller copies it out first),
 * where the cards must stop above the footer image (32 mm when one was
 * printed, otherwise null: piece 2's 6 mm), and where the text of its last
 * page ends, in mm from that page's top (ruling R17), so the cards are kept
 * off it.
 */
final readonly class RenderedTemplate
{
    public function __construct(
        public string $path,
        public ?int $cardFloor,
        public ?float $contentBottom,
    ) {}
}
