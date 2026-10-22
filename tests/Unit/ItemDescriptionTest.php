<?php

namespace Tests\Unit;

use App\Support\ItemDescription;
use PHPUnit\Framework\TestCase;

class ItemDescriptionTest extends TestCase
{
    public function test_legacy_codes_expand_to_complete_product_names(): void
    {
        $this->assertSame('4 × Slim 5-Gal Refill, 1 × Round 5-Gal Refill', ItemDescription::fromLines([], '4S+1R'));
        $this->assertSame('2 × New Slim 5-Gal Jug, 1 × New Round 5-Gal Jug', ItemDescription::fromLines([], '2x newS, 1x newR'));
        $this->assertSame('3 × Round 5-Gal Refill', ItemDescription::fromLines([], '3x round'));
        $this->assertSame('Soap + sponge', ItemDescription::fromLines([], 'Soap + sponge'));
        $this->assertSame('—', ItemDescription::fromLines([], '0 gal'));
    }

    public function test_receipt_snapshot_names_take_precedence_over_codes(): void
    {
        $lines = [(object) ['quantity' => 2, 'item_name' => 'Original Retail Jug Name']];
        $this->assertSame('2 × Original Retail Jug Name', ItemDescription::fromLines($lines, '2S'));
    }
}
