<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LegacyInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_cleanup_keeps_quantities_and_audit_history_and_repairs_recipes(): void
    {
        $this->seed();
        $owner = User::where('username', 'admin')->firstOrFail();
        $stock = InventoryItem::orderBy('id')->pluck('stock_on_hand', 'id')->all();
        $legacy = InventoryItem::create(['item_name' => 'SHRINK_SLIM', 'category' => 'Consumable', 'unit' => 'pcs', 'stock_on_hand' => 5997]);
        $movement = StockMovement::create(['inventory_item_id' => $legacy->id, 'type' => 'restock', 'qty' => 6000, 'user_id' => $owner->id, 'notes' => 'Legacy opening stock']);
        $oldJug = InventoryItem::create(['item_name' => '5-Gal Replacement Slim Jugs', 'category' => 'Asset', 'unit' => 'units', 'stock_on_hand' => 40]);
        Product::whereKey('newS')->update(['inventory_item_id' => $oldJug->id]);
        DB::table('product_consumables')->insert(['product_id' => 'slim', 'inventory_item_id' => $legacy->id, 'qty_per_sale' => 3, 'created_at' => now(), 'updated_at' => now()]);

        (new CatalogSeeder)->retireLegacyInventory();

        $this->assertSame(7, InventoryItem::current()->count());
        $this->assertSame($stock, InventoryItem::current()->orderBy('id')->pluck('stock_on_hand', 'id')->all());
        $this->assertNotNull($legacy->fresh()->retired_at);
        $this->assertSame(5997, $legacy->fresh()->stock_on_hand);
        $this->assertNotNull($movement->fresh());
        $this->assertDatabaseMissing('product_consumables', ['product_id' => 'slim', 'inventory_item_id' => $legacy->id]);
        $this->assertSame(4, DB::table('product_consumables')->where('product_id', 'slim')->count());
        $this->assertSame(InventoryItem::where('item_name', '5 Gallon Slim Jug')->firstOrFail()->id, Product::findOrFail('newS')->inventory_item_id);
        $this->assertDatabaseMissing('product_consumables', ['product_id' => 'newS', 'inventory_item_id' => $oldJug->id]);
        $this->actingAs($owner)->getJson('/api/inventory')->assertOk()->assertJsonCount(7, 'inventory');
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonCount(7, 'inventory');
        $this->putJson('/api/products/slim/recipe', ['consumables' => [['inventory_item_id' => $legacy->id, 'qty_per_sale' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('consumables.0.inventory_item_id');
        $this->getJson('/api/inventory/'.$legacy->id.'/movements')->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson('/api/inventory/'.$legacy->id, ['stock_on_hand' => 100])
            ->assertUnprocessable()->assertJsonValidationErrors('inventory');
        $this->postJson('/api/inventory/'.$legacy->id.'/movements', ['type' => 'restock', 'qty' => 10, 'supplier_id' => 2, 'notes' => 'Attempt to restock retired stock'])
            ->assertUnprocessable()->assertJsonValidationErrors('inventory');
    }

    public function test_reseeding_keeps_custom_stock_and_legacy_audit_records(): void
    {
        $this->seed();
        $custom = InventoryItem::create(['item_name' => 'Custom supplies', 'category' => 'Consumable', 'unit' => 'pcs', 'stock_on_hand' => 123]);
        $legacy = InventoryItem::create(['item_name' => 'CLEAR_COVER', 'category' => 'Consumable', 'unit' => 'pcs', 'stock_on_hand' => 456]);
        $movement = StockMovement::create(['inventory_item_id' => $legacy->id, 'type' => 'restock', 'qty' => 456, 'user_id' => User::firstOrFail()->id, 'notes' => 'Historical stock']);

        $this->seed(CatalogSeeder::class);

        $this->assertSame(123, $custom->fresh()->stock_on_hand);
        $this->assertSame(456, $legacy->fresh()->stock_on_hand);
        $this->assertNotNull($legacy->fresh()->retired_at);
        $this->assertNotNull($movement->fresh());
        $this->assertSame(8, InventoryItem::current()->count());
    }

    public function test_a_retired_recipe_ingredient_blocks_checkout_and_rolls_back_the_sale(): void
    {
        $this->seed();
        InventoryItem::where('item_name', 'Clear Cover')->update(['retired_at' => now()]);
        $this->actingAs(User::where('username', 'cashier')->firstOrFail());
        $this->postJson('/api/cart/availability', ['items' => [['product_id' => 'slim', 'quantity' => 1]]])
            ->assertOk()->assertJsonPath('valid', false);
        $this->postJson('/api/transactions', ['customer_id' => Customer::where('system_key', 'walk-in')->firstOrFail()->id,
            'order_type' => 'Walk-in', 'cash_tendered' => 50, 'submission_key' => (string) Str::uuid(),
            'items' => [['product_id' => 'slim', 'quantity' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }
}
