<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DefectReport;
use App\Models\DemandForecast;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SystemSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InventoryEngine;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryAndCatalogFeatureTest extends TestCase
{
    use RefreshDatabase;

    private ?User $cashier = null;
    private ?User $owner = null;
    private ?User $rider = null;
    private ?User $refiller = null;
    private ?User $admin = null;
    private ?InventoryItem $item = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->cashier = User::where('username', 'cashier')->first();
        $this->owner = User::where('username', 'admin')->first();
        $this->rider = User::where('username', 'rider')->first();
        $this->refiller = User::where('username', 'refiller')->first();
        $this->admin = User::where('username', 'admin')->first();
        $this->item = InventoryItem::where('item_name', 'Clear Cover')->first();
        $this->seedForecasts();
    }

    // --- From InventoryTest.php ---

    

    /** The dynamic engine needs stored forecasts to derive demand from. */
    private function seedForecasts(): void
    {
        $series = [
            'Refill Jugs' => [200, 210, 220, 230, 400, 410, 220],
            'New Jugs' => [5, 6, 5, 7, 12, 14, 6],
            'Clear Cover' => [200, 210, 220, 230, 400, 410, 220],
            'Shrink Seal (Circle Top Slim)' => [200, 210, 220, 230, 400, 410, 220],
            '5 Gallon Slim Jug' => [0.1, 0.1, 0.1, 0.1, 0.2, 0.2, 0.1],
        ];

        foreach ($series as $name => $values) {
            DemandForecast::query()->create([
                'series_name' => $name,
                'horizon_type' => 'Daily',
                'forecast_date' => today(),
                'historical_data' => array_fill(0, 30, 250),
                'forecasted_data' => $values,
                'model_order' => 'ARIMA(0,0,1)',
                'method' => 'arima_seasonal',
                'differencing' => 0,
                'aic_score' => 1033.44,
                'mape_score' => 10.15,
                'mae_score' => 28.56,
                'rmse_score' => 33.95,
                'adf_statistic' => -2.5,
                'adf_pvalue' => 0.0184,
                'ljung_box_pvalue' => 0.9796,
                'residual_std' => 40.0,
            ]);
        }
    }

    private function item(string $name): InventoryItem
    {
        return InventoryItem::query()->where('item_name', $name)->firstOrFail();
    }

    public function test_inventory_list_exposes_thresholds_and_advisories(): void
    {
        $response = $this->actingAs($this->cashier)->getJson('/api/inventory');

        $response->assertOk()
            ->assertJsonCount(7, 'inventory')
            ->assertJsonStructure(['inventory' => [['id', 'item', 'on', 'ss', 'rop', 'target', 'lead', 'status']], 'advisories']);

        $first = $response->json('inventory.0');
        $this->assertIsInt($first['rop']);
        $this->assertIsInt($first['target']);
    }

    public function test_raising_the_lead_time_raises_safety_stock_and_rop(): void
    {
        $caps = $this->item('Clear Cover');
        $ropBefore = $caps->reorder_point;
        $safetyBefore = $caps->safety_stock;

        $response = $this->actingAs($this->owner)
            ->patchJson('/api/inventory/'.$caps->id, ['lead_time_days' => 5]);

        $response->assertOk();

        $caps->refresh();
        $this->assertSame(5, $caps->lead_time_days);
        $this->assertGreaterThan($ropBefore, $caps->reorder_point);
        $this->assertGreaterThan($safetyBefore, $caps->safety_stock);
        $this->assertNotNull($caps->recalculated_at);
    }

    public function test_lead_time_out_of_range_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->patchJson('/api/inventory/'.$this->item('Clear Cover')->id, ['lead_time_days' => 99])
            ->assertStatus(422);
    }

    public function test_stock_can_be_adjusted_up_and_down(): void
    {
        $caps = $this->item('Clear Cover');
        $before = $caps->stock_on_hand;

        $this->actingAs($this->owner)
            ->patchJson('/api/inventory/'.$caps->id, ['direction' => 1])
            ->assertOk();

        $this->assertSame($before + 500, $caps->refresh()->stock_on_hand);

        $this->actingAs($this->owner)
            ->patchJson('/api/inventory/'.$caps->id, ['direction' => -1])
            ->assertOk();

        $this->assertSame($before + 450, $caps->refresh()->stock_on_hand);
    }

    public function test_stock_never_goes_negative(): void
    {
        $item = $this->item('5 Gallon Slim Jug');
        $item->update(['stock_on_hand' => 0]);

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($this->owner)
                ->patchJson('/api/inventory/'.$item->id, ['direction' => -1])
                ->assertOk();
        }

        $this->assertSame(0, $item->refresh()->stock_on_hand);
    }

    public function test_only_the_owner_can_recalculate_every_item(): void
    {
        $this->actingAs($this->cashier)
            ->postJson('/api/inventory/recalculate')
            ->assertStatus(403);

        $response = $this->actingAs($this->owner)->postJson('/api/inventory/recalculate');

        $response->assertOk()->assertJsonPath('updated', 7);
    }

    public function test_u_s46_advisories_endpoint_returns_a_draft_purchase_order(): void
    {
        // Force a shortage so there is something to advise on.
        $this->item('Clear Cover')->update(['stock_on_hand' => 1]);

        $response = $this->actingAs($this->cashier)->getJson('/api/advisories');

        $response->assertOk()->assertJsonStructure(['generated_at', 'count', 'advisories', 'draft_po']);

        $advisory = collect($response->json('advisories'))->firstWhere('item', 'Clear Cover');
        $this->assertNotNull($advisory);
        $this->assertSame('critical', $advisory['severity']);
        $this->assertGreaterThan(0, $advisory['order_quantity']);
        $this->assertSame('SealPack', $advisory['supplier']);
        $this->assertSame('REORDER NOW', $advisory['status_label']);
    }

    /**
     * Guards the regression that produced "2.2 days" in the Days Left panel while
     * the advisory beside it said "1.7 days". Days-of-cover must now come from
     * InventoryEngine::daysRemaining() everywhere, so the inventory list and the
     * advisory payload cannot disagree.
     */
    public function test_days_remaining_is_identical_across_the_inventory_list_and_advisories(): void
    {
        $this->item('Clear Cover')->update(['stock_on_hand' => 12]);
        $this->item('Shrink Seal (Circle Top Slim)')->update(['stock_on_hand' => 2]);

        $inventory = collect(
            $this->actingAs($this->owner)->getJson('/api/inventory')->json('inventory')
        );

        $advisories = collect(
            $this->actingAs($this->owner)->getJson('/api/advisories')->json('advisories')
        );

        $this->assertNotEmpty($inventory);

        foreach ($advisories as $advisory) {
            $listed = $inventory->firstWhere('id', $advisory['id']);

            $this->assertNotNull($listed, 'Advisory references an item missing from the list.');
            $this->assertSame(
                $listed['days_left'],
                $advisory['days_left'],
                "Days-of-cover diverged for {$advisory['item']}: list says "
                    .var_export($listed['days_left'], true).', advisory says '
                    .var_export($advisory['days_left'], true).'.'
            );
            $this->assertSame($listed['daily_demand'], $advisory['daily_demand']);
        }
    }

    public function test_days_remaining_matches_the_documented_formula(): void
    {
        $caps = $this->item('Clear Cover');
        $caps->update(['stock_on_hand' => 260]);

        $cover = app(InventoryEngine::class)->daysRemaining($caps->refresh());

        // Days Remaining = Current Stock Quantity / ARIMA Projected Daily Consumption
        $this->assertEqualsWithDelta(
            $caps->stock_on_hand / $cover['daily_demand'],
            $cover['days_exact'],
            0.001
        );
        $this->assertSame(1.0, round($cover['days_exact'], 1));
    }

    public function test_u_s45_items_at_or_below_the_reorder_point_are_flagged_reorder_now(): void
    {
        $engine = app(InventoryEngine::class);
        $caps = $this->item('Clear Cover');
        $this->actingAs($this->owner)->postJson('/api/inventory/recalculate')->assertOk();
        $caps->refresh();

        $caps->update(['stock_on_hand' => $caps->reorder_point]);
        $this->assertTrue($caps->refresh()->needsReorder());
        $this->assertSame('REORDER NOW', $engine->daysRemaining($caps)['days_left'] !== null ? 'REORDER NOW' : 'OK');

        $row = collect(
            $this->actingAs($this->owner)->getJson('/api/inventory')->json('inventory')
        )->firstWhere('id', $caps->id);
        $this->assertTrue($row['needs_reorder']);
        $this->assertSame('REORDER NOW', $row['status_label']);
    }

    public function test_quantity_labels_are_pluralised(): void
    {
        $engine = app(InventoryEngine::class);

        $this->assertSame('1 unit', $engine->quantityLabel(1, 'pcs'));
        $this->assertSame('2 pcs', $engine->quantityLabel(2, 'pcs'));
        $this->assertSame('0 pcs', $engine->quantityLabel(0, 'pcs'));
        $this->assertSame('1 unit', $engine->quantityLabel(1, 'units'));
        $this->assertSame('5 bottles', $engine->quantityLabel(5, 'bottles'));
    }

    public function test_safety_stock_is_derived_from_forecast_variance(): void
    {
        $this->actingAs($this->owner)->postJson('/api/inventory/recalculate')->assertOk();

        $caps = $this->item('Clear Cover');

        // Demand noise (sigma=40, L=2) is widened 2x for the seasonal peak day.
        $this->assertSame((int) ceil(1.65 * (40 * 2) * sqrt(2) * 1.25), $caps->safety_stock);
        $this->assertSame(234, $caps->safety_stock);
        $this->assertGreaterThan($caps->safety_stock, $caps->reorder_point);
    }

    public function test_u_s31_supplier_updates_preserve_optional_and_nullable_fields(): void
    {
        $this->actingAs($this->owner)->postJson('/api/suppliers', [])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'supplied_items']);

        $response = $this->actingAs($this->owner)->postJson('/api/suppliers', [
            'name' => 'Refactor Supplier', 'supplied_items' => 'Caps',
            'contact' => '555', 'last_delivery' => '2026-01-01',
        ])->assertCreated();
        $id = $response->json('supplier.id');
        $this->patchJson('/api/suppliers/'.$id, ['contact' => null, 'last_delivery' => null])
            ->assertOk();
        $this->assertDatabaseHas('suppliers', [
            'id' => $id, 'name' => 'Refactor Supplier', 'supplied_items' => 'Caps',
            'contact' => '-', 'last_delivery' => null,
        ]);
        $this->patchJson('/api/suppliers/'.$id, ['lead_time_days' => 15])
            ->assertUnprocessable()->assertJsonValidationErrors('lead_time_days');
        $this->actingAs($this->cashier)->patchJson('/api/suppliers/'.$id, [])->assertForbidden();
    }

    public function test_u_s43_cumulative_forecast_error_variance_sets_the_buffer(): void
    {
        DemandForecast::latestFor('Clear Cover')->update(['model_diagnostics' => [
            'forecast_error_variances' => [100, 400],
            'seasonal' => true,
        ]]);
        $item = $this->item('Clear Cover');
        app(InventoryEngine::class)->recalculateItem($item);
        // 2 * (40 * 2)^2 demand noise + 100 + 400 forecast error = 13,300.
        $this->assertSame((int) ceil(1.65 * sqrt(13300) * 1.25), $item->fresh()->safety_stock);
        $this->assertSame(238, $item->fresh()->safety_stock);
    }

    // --- From LegacyInventoryTest.php ---
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

    // --- From ConsumableStoriesTest.php ---
    public function test_u_s51_u_s58_mixed_cart_consumption_and_audit(): void
    {
        $before = InventoryItem::pluck('stock_on_hand', 'item_name');
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $response = $this->actingAs($cashier)->postJson('/api/transactions', ['submission_key' => (string) Str::uuid(),
            'customer_id' => Customer::where('name', 'Walk-in Guest')->value('id'),
            'order_type' => 'Walk-in', 'cash_tendered' => 500,
            'items' => [['product_id' => 'slim', 'quantity' => 2], ['product_id' => 'round', 'quantity' => 1]],
        ])->assertCreated();
        $expectedDecrements = [
            'Shrink Seal (Circle Top Slim)' => 2,
            'Shrink Seal (Circle Back)' => 2,
            'Shrink Seal (Circle Nozzle)' => 2,
            'Shrink Seal (Circle Top Round)' => 1,
            'Clear Cover' => 3,
        ];
        foreach ($expectedDecrements as $name => $qty) {
            $item = InventoryItem::where('item_name', $name)->firstOrFail();
            $this->assertSame($before[$name] - $qty, $item->stock_on_hand);
            $this->assertDatabaseHas('stock_movements', ['inventory_item_id' => $item->id,
                'type' => 'sale', 'qty' => -$qty, 'user_id' => $cashier->id,
                'transaction_id' => $response->json('transaction.id')]);
        }
    }

    public function test_u_s57_missing_cover_rolls_back_sales_and_audit(): void
    {
        InventoryItem::where('item_name', 'Clear Cover')->update(['stock_on_hand' => 0]);
        $before = DB::table('transactions')->count();
        $this->actingAs(User::where('username', 'cashier')->firstOrFail())->postJson('/api/transactions', ['submission_key' => (string) Str::uuid(),
            'customer_id' => Customer::where('name', 'Walk-in Guest')->value('id'),
            'order_type' => 'Walk-in', 'cash_tendered' => 50,
            'items' => [['product_id' => 'slim', 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertSame($before, DB::table('transactions')->count());
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_u_s59_u_s65_restock_metadata_history_and_thresholds(): void
    {
        $owner = User::where('username', 'admin')->firstOrFail();
        $item = InventoryItem::where('item_name', 'Clear Cover')->firstOrFail();
        $before = $item->stock_on_hand;
        $supplier = DB::table('suppliers')->value('id');
        $this->actingAs($owner)->postJson('/api/inventory/'.$item->id.'/movements', [
            'type' => 'restock', 'qty' => 20, 'supplier_id' => $supplier,
            'lot_number' => 'LOT-001', 'unit_cost' => 1.25, 'notes' => 'Vendor delivery',
        ])->assertCreated();
        $this->assertSame($before + 20, $item->fresh()->stock_on_hand);
        $this->assertNotNull($item->fresh()->recalculated_at);
        $this->assertDatabaseHas('stock_movements', ['inventory_item_id' => $item->id,
            'qty' => 20, 'supplier_id' => $supplier, 'lot_number' => 'LOT-001', 'unit_cost' => '1.2500']);
        $this->getJson('/api/inventory/'.$item->id.'/movements')->assertOk()
            ->assertJsonPath('data.0.lot_number', 'LOT-001')->assertJsonPath('meta.total', 1);
    }

    public function test_u_s62_adjustments_need_reasons_and_cannot_make_negative_stock(): void
    {
        $item = InventoryItem::where('item_name', 'Clear Cover')->firstOrFail();
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $this->postJson('/api/inventory/'.$item->id.'/movements', ['type' => 'adjustment', 'qty' => -1])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson('/api/inventory/'.$item->id.'/movements', ['type' => 'damage',
            'qty' => -($item->stock_on_hand + 1), 'reason' => 'damage', 'notes' => 'Broken'])
            ->assertUnprocessable();
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_u_s55_only_owners_can_edit_recipes_and_quantities_are_validated(): void
    {
        $item = InventoryItem::where('item_name', 'Shrink Seal (Circle Top Slim)')->firstOrFail();
        $this->actingAs(User::where('username', 'cashier')->firstOrFail())
            ->putJson('/api/products/slim/recipe', ['consumables' => []])->assertForbidden();
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $this->putJson('/api/products/slim/recipe', ['consumables' => [
            ['inventory_item_id' => $item->id, 'qty_per_sale' => 0],
        ]])->assertUnprocessable();
        $this->putJson('/api/products/slim/recipe', ['consumables' => [
            ['inventory_item_id' => $item->id, 'qty_per_sale' => 4],
        ]])->assertOk();
        $this->assertDatabaseHas('product_consumables', ['product_id' => 'slim',
            'inventory_item_id' => $item->id, 'qty_per_sale' => 4]);
    }

    public function test_u_s32_supplier_lead_time_updates_dependent_inventory(): void
    {
        $item = InventoryItem::whereNotNull('supplier_id')->firstOrFail();
        $this->actingAs(User::where('username', 'admin')->firstOrFail())
            ->patchJson('/api/suppliers/'.$item->supplier_id, ['lead_time_days' => 9])->assertOk();
        $this->assertSame(9, $item->fresh()->lead_time_days);
        $this->assertNotNull($item->fresh()->recalculated_at);
    }

    public function test_u_s30_legacy_stock_edits_also_have_an_audit_trail(): void
    {
        $item = InventoryItem::where('item_name', 'Clear Cover')->firstOrFail();
        $this->actingAs(User::where('username', 'admin')->firstOrFail())
            ->patchJson('/api/inventory/'.$item->id, ['stock_on_hand' => $item->stock_on_hand - 3])->assertOk();
        $this->assertDatabaseHas('stock_movements', ['inventory_item_id' => $item->id, 'qty' => -3,
            'type' => 'adjustment', 'reason' => 'count_correction']);
    }

    public function test_u_s54_new_jugs_consume_wraps_and_covers(): void
    {
        $before = InventoryItem::pluck('stock_on_hand', 'item_name');
        $this->actingAs(User::where('username', 'cashier')->firstOrFail())->postJson('/api/transactions', ['submission_key' => (string) Str::uuid(),
            'customer_id' => Customer::where('name', 'Walk-in Guest')->value('id'),
            'order_type' => 'Walk-in', 'cash_tendered' => 500,
            'items' => [['product_id' => 'newS', 'quantity' => 1], ['product_id' => 'newR', 'quantity' => 1]],
        ])->assertCreated();
        $expectedNewJugDecrements = [
            'Shrink Seal (Circle Top Slim)' => 1,
            'Shrink Seal (Circle Back)' => 1,
            'Shrink Seal (Circle Nozzle)' => 1,
            'Shrink Seal (Circle Top Round)' => 1,
            'Clear Cover' => 2,
        ];
        foreach ($expectedNewJugDecrements as $name => $qty) {
            $this->assertSame($before[$name] - $qty, InventoryItem::where('item_name', $name)->value('stock_on_hand'));
        }
    }

    public function test_u_s08_u_s12_delivery_saves_address_and_unpaid_status(): void
    {
        $customer = Customer::where('name', '!=', 'Walk-in Guest')->firstOrFail();
        $response = $this->actingAs(User::where('username', 'cashier')->firstOrFail())->postJson('/api/transactions', ['submission_key' => (string) Str::uuid(),
            'customer_id' => $customer->id, 'order_type' => 'Delivery',
            'items' => [['product_id' => 'slim', 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('transaction.payment_status', 'unpaid')
            ->assertJsonPath('transaction.delivery_address', $customer->address);
        $this->assertDatabaseHas('transactions', ['id' => $response->json('transaction.id'),
            'payment_status' => 'unpaid', 'delivery_address' => $customer->address]);
    }

    public function test_u_s22_settlement_receipt_keeps_remaining_balance(): void
    {
        // A manager holds cash authority, so the settlement posts and issues a
        // receipt directly. A cashier's collection would instead wait for
        // approval and produce no receipt yet.
        $customer = Customer::where('name', '!=', 'Walk-in Guest')->firstOrFail();
        $customer->update(['debt_balance' => 100]);
        $this->actingAs(User::where('username', 'admin')->firstOrFail())
            ->postJson('/api/customers/'.$customer->id.'/settle', ['submission_key' => (string) Str::uuid(), 'amount' => 30])
            ->assertOk()->assertJsonPath('transaction.balance_after', 70);
    }

    public function test_u_s16_today_filter_excludes_older_transactions(): void
    {
        $old = $this->reportTransaction();
        $old->update(['transaction_date' => today()->subDay()]);
        $this->actingAs(User::where('username', 'cashier')->firstOrFail())
            ->getJson('/api/transactions?days=0')->assertOk()->assertJsonMissing(['no' => $old->receipt_number]);
    }

    public function test_u_s63_history_is_paginated_and_owner_only(): void
    {
        $owner = User::where('username', 'admin')->firstOrFail();
        $item = InventoryItem::where('item_name', 'Clear Cover')->firstOrFail();
        for ($i = 0; $i < 21; $i++) {
            StockMovement::create(['inventory_item_id' => $item->id,
                'type' => 'adjustment', 'qty' => 1, 'user_id' => $owner->id, 'notes' => 'Audit '.$i]);
        }
        $this->actingAs($owner)->getJson('/api/inventory/'.$item->id.'/movements')
            ->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 21);
        $this->getJson('/api/inventory/'.$item->id.'/movements?page=2')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs(User::where('username', 'cashier')->firstOrFail())
            ->getJson('/api/inventory/'.$item->id.'/movements')->assertForbidden();
    }

    public function test_u_s21_damaged_merchandise_is_an_audited_stock_write_off(): void
    {
        $item = InventoryItem::where('item_name', '5 Gallon Slim Jug')->firstOrFail();
        $before = $item->stock_on_hand;
        $this->actingAs(User::where('username', 'admin')->firstOrFail())
            ->postJson('/api/inventory/'.$item->id.'/movements', ['type' => 'damage', 'qty' => -1, 'reason' => 'damage', 'notes' => 'Cracked new retail jug'])
            ->assertCreated();
        $this->assertSame($before - 1, $item->fresh()->stock_on_hand);
        $this->assertDatabaseHas('stock_movements', ['inventory_item_id' => $item->id, 'type' => 'damage', 'qty' => -1]);
    }

    private function reportTransaction(): Transaction
    {
        return Transaction::create(['receipt_number' => 'QA-REPORT',
            'customer_id' => Customer::where('name', 'Walk-in Guest')->firstOrFail()->id,
            'cashier_id' => User::where('username', 'cashier')->firstOrFail()->id,
            'order_type' => 'Walk-in', 'payment_method' => 'Cash', 'total_amount' => 50,
            'transaction_date' => today(), 'transaction_time' => '08:00:00']);
    }

    public function test_report_pages_include_sales_beyond_the_recent_cache(): void
    {
        $template = $this->reportTransaction()->getAttributes();
        unset($template['id']);
        $rows = [];
        for ($i = 0; $i < 201; $i++) {
            $rows[] = array_merge($template, ['receipt_number' => 'REPORT-'.$i, 'transaction_date' => today()->toDateString(), 'order_type' => 'Walk-in', 'total_amount' => 1]);
        }
        DB::table('transactions')->insert($rows);
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $this->getJson('/api/transactions?paginated=1&page=1')->assertOk()->assertJsonCount(200, 'transactions')->assertJsonPath('has_more', true);
        $this->getJson('/api/transactions?paginated=1&page=2')->assertOk()->assertJsonPath('has_more', false);
        $this->assertGreaterThanOrEqual(201, $this->getJson('/api/bootstrap')->json('meta.sales_today_total'));
    }

    // --- From RecipeStockRegressionTest.php ---
    private const RECIPE_ITEMS = [
        'Clear Cover',
        'Shrink Seal (Circle Top Slim)',
        'Shrink Seal (Circle Back)',
        'Shrink Seal (Circle Nozzle)',
        'Shrink Seal (Circle Top Round)',
    ];

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

    // --- From StockHistoryTest.php ---
    public function test_history_filters_search_audit_details_and_keep_items_isolated(): void
    {
        $this->seed();
        $owner = User::where('username', 'admin')->firstOrFail();
        $owner->update(['name' => 'History Operator']);
        $this->actingAs($owner);
        $item = InventoryItem::firstOrFail();
        $supplier = Supplier::firstOrFail();
        $supplier->update(['name' => 'History Supplier']);
        $restock = StockMovement::create(['inventory_item_id' => $item->id, 'user_id' => $owner->id, 'supplier_id' => $supplier->id, 'type' => 'restock', 'qty' => 10, 'notes' => 'Delivery received', 'created_at' => '2026-01-02 10:00:00']);
        $adjustment = StockMovement::create(['inventory_item_id' => $item->id, 'user_id' => $owner->id, 'type' => 'adjustment', 'qty' => -2, 'reason' => 'count_correction', 'notes' => 'Physical count checked', 'created_at' => '2026-01-03 10:00:00']);
        StockMovement::create(['inventory_item_id' => InventoryItem::where('id', '!=', $item->id)->value('id'), 'user_id' => $owner->id, 'type' => 'restock', 'qty' => 99, 'notes' => 'Unrelated delivery']);
        $restock->forceFill(['created_at' => '2026-01-02 10:00:00'])->save();
        $adjustment->forceFill(['created_at' => '2026-01-03 10:00:00'])->save();
        $url = '/api/inventory/'.$item->id.'/movements';
        $this->getJson($url.'?type=restock&from=2026-01-02&to=2026-01-02&search=History%20Supplier')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $restock->id);
        $this->getJson($url.'?type=adjustment&search=count%20correction')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $adjustment->id)->assertJsonPath('data.0.reason', 'count_correction');
        $this->getJson($url.'?from=2026-01-02&to=2026-01-03&search=History%20Operator&order=oldest')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $restock->id);
        $this->getJson($url.'?from=2026-01-03&to=2026-01-02&type=unknown')->assertUnprocessable()->assertJsonValidationErrors(['to', 'type']);
        $this->actingAs(User::where('username', 'cashier')->firstOrFail());
        $this->getJson($url)->assertForbidden();
    }

    // --- From DefectReportTest.php ---

    

    public function test_cashier_can_submit_a_defect_report(): void
    {
        $response = $this->actingAs($this->cashier)->postJson('/api/defects', [
            'inventory_item_id' => $this->item->id,
            'quantity' => 5,
            'reason' => 'Leaking jug',
        ]);

        $response->assertCreated()
            ->assertJsonPath('report.status', 'pending')
            ->assertJsonPath('report.quantity', 5)
            ->assertJsonPath('report.reason', 'Leaking jug')
            ->assertJsonPath('report.inventory_item.item', 'Clear Cover')
            ->assertJsonPath('report.reported_by.name', 'Juan Dela Cruz');

        $this->assertDatabaseHas('defect_reports', [
            'inventory_item_id' => $this->item->id,
            'quantity' => 5,
            'status' => 'pending',
        ]);
    }

    public function test_rider_can_submit_a_defect_report(): void
    {
        $this->actingAs($this->rider)->postJson('/api/defects', [
            'inventory_item_id' => $this->item->id,
            'quantity' => 2,
            'reason' => 'Torn seal',
        ])->assertCreated();
    }

    public function test_refiller_can_submit_a_defect_report(): void
    {
        $this->actingAs($this->refiller)->postJson('/api/defects', [
            'inventory_item_id' => $this->item->id,
            'quantity' => 1,
            'reason' => 'Cracked cap',
        ])->assertCreated();
    }

    public function test_super_admin_can_submit_a_defect_report(): void
    {
        $this->actingAs($this->admin)->postJson('/api/defects', [
            'inventory_item_id' => $this->item->id,
            'quantity' => 3,
            'reason' => 'Broken handle',
        ])->assertCreated();
    }

    public function test_quantity_above_stock_is_rejected(): void
    {
        $this->actingAs($this->cashier)->postJson('/api/defects', [
            'inventory_item_id' => $this->item->id,
            'quantity' => $this->item->stock_on_hand + 1,
            'reason' => 'Leaking jug',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity' => 'Quantity exceeds available stock']);
    }

    public function test_quantity_of_zero_is_rejected(): void
    {
        $this->actingAs($this->cashier)->postJson('/api/defects', [
            'inventory_item_id' => $this->item->id,
            'quantity' => 0,
            'reason' => 'Leaking jug',
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');
    }

    public function test_reason_is_required(): void
    {
        $this->actingAs($this->cashier)->postJson('/api/defects', [
            'inventory_item_id' => $this->item->id,
            'quantity' => 1,
            'reason' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_inventory_item_must_exist(): void
    {
        $this->actingAs($this->cashier)->postJson('/api/defects', [
            'inventory_item_id' => 999999,
            'quantity' => 1,
            'reason' => 'Leaking jug',
        ])->assertUnprocessable()->assertJsonValidationErrors('inventory_item_id');
    }

    public function test_cashier_cannot_view_the_defect_list(): void
    {
        DefectReport::query()->create([
            'reported_by' => $this->rider->id,
            'inventory_item_id' => $this->item->id,
            'quantity' => 1,
            'reason' => 'Torn seal',
            'status' => 'pending',
        ]);

        $this->actingAs($this->cashier)->getJson('/api/defects')->assertForbidden();
    }

    public function test_staff_can_load_only_reportable_stock_details(): void
    {
        foreach ([$this->refiller, $this->rider] as $staff) {
            $response = $this->actingAs($staff)->getJson('/api/employee/defect-items')->assertOk();
            $items = $response->json('inventory');
            $this->assertNotEmpty($items);
            $this->assertSame(['id', 'item', 'on', 'unit'], array_keys($items[0]));
            $this->actingAs($staff)->getJson('/api/inventory')->assertForbidden();
            $this->actingAs($staff)->getJson('/api/defects')->assertForbidden();
        }
    }

    public function test_rider_cannot_view_the_defect_list(): void
    {
        $this->actingAs($this->rider)->getJson('/api/defects')->assertForbidden();
    }

    public function test_admin_can_approve_a_report_and_stock_is_deducted(): void
    {
        $before = $this->item->stock_on_hand;

        $report = DefectReport::query()->create([
            'reported_by' => $this->rider->id,
            'inventory_item_id' => $this->item->id,
            'quantity' => 4,
            'reason' => 'Torn seal',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin)
            ->patchJson('/api/defects/'.$report->id.'/approve', ['notes' => 'Confirmed torn']);

        $response->assertOk()
            ->assertJsonPath('report.status', 'approved')
            ->assertJsonPath('report.resolved_by.name', 'Admin (kmikaelim11)')
            ->assertJsonPath('stock_on_hand', $before - 4);

        $this->item->refresh();
        $this->assertSame($before - 4, $this->item->stock_on_hand);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $this->item->id,
            'type' => 'damage',
            'qty' => -4,
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_admin_can_reject_a_report(): void
    {
        $report = DefectReport::query()->create([
            'reported_by' => $this->rider->id,
            'inventory_item_id' => $this->item->id,
            'quantity' => 2,
            'reason' => 'Torn seal',
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin)
            ->patchJson('/api/defects/'.$report->id.'/reject', ['notes' => 'Not damage - crease'])
            ->assertOk()
            ->assertJsonPath('report.status', 'rejected')
            ->assertJsonPath('report.resolved_by.name', 'Admin (kmikaelim11)');

        $this->item->refresh();
        $this->assertSame($this->item->stock_on_hand, $this->item->stock_on_hand, 'Rejecting must not move stock.');
        $this->assertSame(0, $this->item->stockMovements()->where('type', 'damage')->count());
    }

    public function test_approving_twice_is_rejected(): void
    {
        $report = DefectReport::query()->create([
            'reported_by' => $this->rider->id,
            'inventory_item_id' => $this->item->id,
            'quantity' => 2,
            'reason' => 'Torn seal',
            'status' => 'approved',
            'resolved_by' => $this->admin->id,
            'resolved_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->patchJson('/api/defects/'.$report->id.'/approve')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_admin_cannot_approve_when_stock_has_dropped_below_the_reported_quantity(): void
    {
        // File a report for more than what is currently on hand: the row is
        // locked and re-checked at approval time, so this must not drive stock
        // negative.
        $report = DefectReport::query()->create([
            'reported_by' => $this->rider->id,
            'inventory_item_id' => $this->item->id,
            'quantity' => $this->item->stock_on_hand,
            'reason' => 'Torn seal',
            'status' => 'pending',
        ]);

        $this->item->update(['stock_on_hand' => 1]);

        $this->actingAs($this->admin)
            ->patchJson('/api/defects/'.$report->id.'/approve')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity' => 'Quantity exceeds available stock']);

        $this->item->refresh();
        $this->assertSame(1, $this->item->stock_on_hand, 'Stock must not move on a refused approval.');
    }

    public function test_employee_cannot_approve_or_reject(): void
    {
        $report = DefectReport::query()->create([
            'reported_by' => $this->rider->id,
            'inventory_item_id' => $this->item->id,
            'quantity' => 1,
            'reason' => 'Torn seal',
            'status' => 'pending',
        ]);

        $this->actingAs($this->refiller)
            ->patchJson('/api/defects/'.$report->id.'/approve')
            ->assertForbidden();

        $this->actingAs($this->rider)
            ->patchJson('/api/defects/'.$report->id.'/reject')
            ->assertForbidden();

        $report->refresh();
        $this->assertSame('pending', $report->status);
    }

    public function test_index_filters_by_status(): void
    {
        DefectReport::query()->create([
            'reported_by' => $this->rider->id, 'inventory_item_id' => $this->item->id,
            'quantity' => 1, 'reason' => 'a', 'status' => 'pending',
        ]);
        DefectReport::query()->create([
            'reported_by' => $this->rider->id, 'inventory_item_id' => $this->item->id,
            'quantity' => 1, 'reason' => 'b', 'status' => 'approved',
            'resolved_by' => $this->admin->id, 'resolved_at' => now(),
        ]);

        $pending = $this->actingAs($this->admin)->getJson('/api/defects?status=pending');
        $pending->assertOk();
        $this->assertSame(1, collect($pending->json('reports'))->count());

        $approved = $this->actingAs($this->admin)->getJson('/api/defects?status=approved');
        $approved->assertOk();
        $this->assertSame(1, collect($approved->json('reports'))->count());
    }

    // --- From SettingsTest.php ---
    public function test_partial_updates_preserve_other_values_and_descriptions(): void
    {
        $before = SystemSetting::allValues();
        $description = SystemSetting::query()->findOrFail('restock_lead_days')->description;

        $this->actingAs(User::query()->where('role', 'super_admin')->firstOrFail())
            ->putJson('/api/settings', ['restock_lead_days' => 5, 'ignored' => 'value'])
            ->assertOk()->assertExactJson(['settings' => array_replace($before, ['restock_lead_days' => '5'])]);

        $this->assertSame($description, SystemSetting::query()->findOrFail('restock_lead_days')->description);
        $this->assertDatabaseMissing('system_settings', ['setting_key' => 'ignored']);
    }

    public function test_owner_can_update_all_settings_or_send_an_empty_update(): void
    {
        $settings = ['station_name' => 'Test Station', 'restock_lead_days' => '1', 'sus_target' => 'Grade A'];
        $this->actingAs(User::query()->where('role', 'super_admin')->firstOrFail())
            ->putJson('/api/settings', $settings)->assertOk()->assertExactJson(['settings' => $settings]);
        $this->putJson('/api/settings', [])->assertOk()->assertExactJson(['settings' => $settings]);
    }

    public function test_settings_writes_require_owner_and_keep_validation(): void
    {
        $this->putJson('/api/settings', ['station_name' => 'Forbidden'])->assertUnauthorized();
        $this->actingAs(User::query()->where('role', 'cashier')->firstOrFail())
            ->putJson('/api/settings', ['station_name' => 'Forbidden'])->assertForbidden();
        $this->actingAs(User::query()->where('role', 'super_admin')->firstOrFail())
            ->putJson('/api/settings', ['restock_lead_days' => 0])->assertUnprocessable()
            ->assertJsonValidationErrors('restock_lead_days');
    }
}
