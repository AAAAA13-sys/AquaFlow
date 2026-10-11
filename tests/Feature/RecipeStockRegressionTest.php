<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: an upgraded store must still be able to sell.
 *
 * The consumable-recipe migration calls RecipeService::installDefaults() with
 * the default openingStock of 0. On a database that already has products (every
 * existing store), that creates SHRINK_SLIM, SHRINK_ROUND and CLEAR_COVER at
 * zero stock. installDefaults() then uses firstOrCreate, so a later seeder run
 * finds the rows and leaves the zero in place.
 *
 * Because every POS product's recipe needs one of those three items, whole-cart
 * availability collapses to zero and all four tiles render disabled - the till
 * is unusable with no visible reason.
 *
 * Fresh-schema tests never caught this: on an empty database the migration
 * returns early (no products yet), so the seeder creates the items at their
 * demo stock. Only the upgrade path was broken.
 */
class RecipeStockRegressionTest extends TestCase
{
    use RefreshDatabase;

    private const RECIPE_ITEMS = ['SHRINK_SLIM', 'SHRINK_ROUND', 'CLEAR_COVER'];

    /** The exact state an upgraded installation is left in. */
    private function simulateUpgradedStore(): void
    {
        $this->seed();

        InventoryItem::query()
            ->whereIn('item_name', self::RECIPE_ITEMS)
            ->update(['stock_on_hand' => 0]);
    }

    public function test_seeding_restocks_recipe_items_the_migration_created_at_zero(): void
    {
        $this->simulateUpgradedStore();

        // Re-running the catalog seeder models the documented upgrade step.
        $this->seed();

        $starved = InventoryItem::query()
            ->whereIn('item_name', self::RECIPE_ITEMS)
            ->where('stock_on_hand', '<=', 0)
            ->pluck('item_name')
            ->all();

        $this->assertSame([], $starved, 'Recipe ingredients left at zero: '.implode(', ', $starved));
    }

    public function test_every_pos_product_is_sellable_after_an_upgrade(): void
    {
        $this->simulateUpgradedStore();
        $this->seed();

        $response = $this->actingAs(User::query()->where('username', 'cashier')->firstOrFail())
            ->postJson('/api/cart/availability', ['items' => [['product_id' => 'slim', 'quantity' => 1]]]);

        $response->assertOk();

        foreach (['slim', 'round', 'newS', 'newR'] as $productId) {
            $this->assertGreaterThanOrEqual(
                1,
                $response->json('additional.'.$productId),
                "The till cannot sell {$productId}: availability is ".$response->json('additional.'.$productId).'.'
            );
        }
    }

    public function test_a_first_sale_still_succeeds_after_an_upgrade(): void
    {
        $this->simulateUpgradedStore();
        $this->seed();

        $cashier = User::query()->where('username', 'cashier')->firstOrFail();
        $guest = \App\Models\Customer::query()->where('name', 'Walk-in Guest')->firstOrFail();

        $this->actingAs($cashier)
            ->postJson('/api/transactions', [
                'customer_id' => $guest->id,
                'order_type' => 'Walk-in',
                'cash_tendered' => 50,
                'items' => [['product_id' => 'slim', 'quantity' => 1]],
                // Financial idempotency key required by CheckoutRequest.
                'submission_key' => (string) \Illuminate\Support\Str::uuid(),
            ])
            ->assertCreated();
    }
}
