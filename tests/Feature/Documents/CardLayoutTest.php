<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Modules\Documents\Pdf\CardLayout;
use PHPUnit\Framework\TestCase;

/**
 * Where the signature cards go (documents design §11.4): legacy's geometry,
 * rows centred, the first mark at the right.
 */
class CardLayoutTest extends TestCase
{
    public function test_one_card_is_centred(): void
    {
        $placement = (new CardLayout)->place(1, 210, 297);

        $this->assertFalse($placement->newPage);
        $this->assertEqualsWithDelta(82.5, $placement->positions[0]['x'], 0.001);
        $this->assertEqualsWithDelta(266, $placement->positions[0]['y'], 0.001);
        $this->assertEqualsWithDelta(291, $placement->blockBottom, 0.001);
    }

    public function test_five_cards_make_two_rows_the_first_at_the_right(): void
    {
        $placement = (new CardLayout)->place(5, 210, 297);

        $this->assertCount(5, $placement->positions);
        $this->assertEqualsWithDelta(157.5, $placement->positions[0]['x'], 0.001);
        $this->assertEqualsWithDelta(7.5, $placement->positions[3]['x'], 0.001);
        $this->assertEqualsWithDelta($placement->positions[0]['y'], $placement->positions[3]['y'], 0.001);

        $this->assertEqualsWithDelta(82.5, $placement->positions[4]['x'], 0.001);
        $this->assertEqualsWithDelta($placement->positions[0]['y'] + 30, $placement->positions[4]['y'], 0.001);
    }

    public function test_eleven_cards_fit_above_the_line(): void
    {
        $placement = (new CardLayout)->place(11, 210, 297);

        $this->assertFalse($placement->newPage);
        $this->assertCount(11, $placement->positions);
        // three rows: 3 * 25 + 2 * 5 = 85 high, ending 6 above the bottom
        $this->assertEqualsWithDelta(206, $placement->positions[0]['y'], 0.001);
        $this->assertEqualsWithDelta(291, $placement->blockBottom, 0.001);
    }

    public function test_a_short_page_takes_a_new_page(): void
    {
        $this->assertTrue((new CardLayout)->place(8, 210, 100)->newPage);
    }
}
