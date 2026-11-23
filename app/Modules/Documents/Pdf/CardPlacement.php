<?php

declare(strict_types=1);

namespace App\Modules\Documents\Pdf;

/**
 * Where the cards of one print go, in millimetres from the page's top left.
 */
final readonly class CardPlacement
{
    /**
     * @param  array<int, array{x: float, y: float}>  $positions  in mark order
     */
    public function __construct(
        public bool $newPage,
        public array $positions,
        public float $blockBottom,
    ) {}
}
