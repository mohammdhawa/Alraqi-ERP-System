<?php

declare(strict_types=1);

namespace App\Modules\Documents\Pdf;

/**
 * The geometry of the signature cards (documents design §11.4), legacy's:
 * cards 45 x 25 mm, 5 apart, the block 6 from the bottom of the last page,
 * rows centred, the first mark at the right. The block takes a new page
 * when its top would rise above 55% of the page height.
 */
final class CardLayout
{
    public const CARD_WIDTH = 45;

    public const CARD_HEIGHT = 25;

    public const GAP = 5;

    public const BOTTOM_MARGIN = 6;

    public function place(int $count, float $pageWidth, float $pageHeight): CardPlacement
    {
        $perRow = max(1, (int) floor(($pageWidth + self::GAP) / (self::CARD_WIDTH + self::GAP)));
        $rows = max(1, (int) ceil($count / $perRow));
        $height = $rows * self::CARD_HEIGHT + ($rows - 1) * self::GAP;
        $top = $pageHeight - self::BOTTOM_MARGIN - $height;

        $positions = [];

        for ($index = 0; $index < $count; $index++) {
            $row = intdiv($index, $perRow);
            $column = $index % $perRow;
            $inRow = min($perRow, $count - $row * $perRow);
            $startX = ($pageWidth - ($inRow * self::CARD_WIDTH + ($inRow - 1) * self::GAP)) / 2;

            $positions[] = [
                'x' => $startX + ($inRow - 1 - $column) * (self::CARD_WIDTH + self::GAP),
                'y' => $top + $row * (self::CARD_HEIGHT + self::GAP),
            ];
        }

        return new CardPlacement($top < $pageHeight * 0.55, $positions, $top + $height);
    }
}
