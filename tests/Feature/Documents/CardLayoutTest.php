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

    /**
     * Above a letterhead's footer (Global Constraints, "Cards above the
     * footer"): the block's bottom is the floor from the page's foot, and the
     * 55% rule is the same.
     */
    public function test_a_floor_raises_the_block(): void
    {
        $placement = (new CardLayout)->place(1, 210, 297, 32);

        $this->assertFalse($placement->newPage);
        $this->assertEqualsWithDelta(82.5, $placement->positions[0]['x'], 0.001);
        $this->assertEqualsWithDelta(240, $placement->positions[0]['y'], 0.001);
        $this->assertEqualsWithDelta(265, $placement->blockBottom, 0.001);

        // The default is piece 2's 6 mm.
        $this->assertEquals((new CardLayout)->place(5, 210, 297), (new CardLayout)->place(5, 210, 297, CardLayout::BOTTOM_MARGIN));

        // Four rows (115 mm): their top is 176 mm down over 6 mm, under the
        // 55% line (163.35 mm); over 32 mm it is 150 mm, above it.
        $this->assertFalse((new CardLayout)->place(16, 210, 297)->newPage);
        $this->assertTrue((new CardLayout)->place(16, 210, 297, 32)->newPage);
    }

    /**
     * Ruling R17: a template's page records where its last page's text ends
     * (mm from the page's top). A block whose top would rise above that line
     * plus the gap takes a new page, placed there as usual; null (an upload)
     * is piece 2's placement.
     */
    public function test_text_reaching_the_block_takes_a_new_page(): void
    {
        $layout = new CardLayout;

        // One row over a 32 mm floor: its top is 240 mm down.
        $usual = $layout->place(2, 210, 297, 32);
        $this->assertFalse($usual->newPage);
        $this->assertEqualsWithDelta(240, $usual->positions[0]['y'], 0.001);

        $low = $layout->place(2, 210, 297, 32, 250.5);
        $this->assertTrue($low->newPage);
        $this->assertEquals($usual->positions, $low->positions);
        $this->assertEqualsWithDelta($usual->blockBottom, $low->blockBottom, 0.001);

        // The text may come to the gap above the block, not into it.
        $this->assertTrue($layout->place(2, 210, 297, 32, 235.01)->newPage);
        $this->assertFalse($layout->place(2, 210, 297, 32, 235)->newPage);
        $this->assertFalse($layout->place(2, 210, 297, 32, 80)->newPage);

        // Null is no content line: the same as leaving it out.
        $this->assertEquals($layout->place(5, 210, 297), $layout->place(5, 210, 297, CardLayout::BOTTOM_MARGIN, null));
        $this->assertEquals($usual, $layout->place(2, 210, 297, 32, null));

        // The 55% rule is unchanged: short text does not keep four rows over
        // a 32 mm floor on the page.
        $this->assertTrue($layout->place(16, 210, 297, 32, 40)->newPage);
    }
}
