<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_empty_cart_can_check_stock_before_adding_the_first_item(): void
    {
        $this->seed();
        $this->actingAs(User::where('username', 'cashier')->firstOrFail())
            ->postJson('/api/cart/availability', ['items' => []])
            ->assertOk()->assertJsonPath('valid', true)
            ->assertJson(fn ($json) => $json->where('additional.slim', fn ($count) => $count > 0)->etc());
    }

    public function test_shared_ingredients_limit_the_whole_cart_without_deducting_stock(): void
    {
        $this->seed();
        $caps = InventoryItem::where('item_name', 'Non-Spill Caps')->firstOrFail();
        $caps->update(['stock_on_hand' => 3]);
        $this->actingAs(User::where('username', 'cashier')->firstOrFail())
            ->postJson('/api/cart/availability', ['items' => [['product_id' => 'slim', 'quantity' => 2]]])
            ->assertOk()->assertJsonPath('valid', true)->assertJsonPath('additional.round', 1);
        $this->assertSame(3, $caps->fresh()->stock_on_hand);
        $this->postJson('/api/cart/availability', ['items' => [
            ['product_id' => 'slim', 'quantity' => 2], ['product_id' => 'round', 'quantity' => 2],
        ]])->assertOk()->assertJsonPath('valid', false);
    }

    public function test_combined_duplicate_lines_cannot_bypass_quantity_limit(): void
    {
        $this->seed();
        $this->actingAs(User::where('username', 'cashier')->firstOrFail())
            ->postJson('/api/cart/availability', ['items' => [
                ['product_id' => 'slim', 'quantity' => 600], ['product_id' => 'slim', 'quantity' => 600],
            ]])->assertUnprocessable();
    }
}
