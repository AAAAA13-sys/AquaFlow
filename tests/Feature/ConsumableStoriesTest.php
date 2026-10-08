<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConsumableStoriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_u_s51_u_s58_mixed_cart_consumption_and_audit(): void
    {
        $before = InventoryItem::pluck('stock_on_hand', 'item_name');
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $response = $this->actingAs($cashier)->postJson('/api/transactions', ['submission_key' => (string) Str::uuid(),
            'customer_id' => Customer::where('name', 'Walk-in Guest')->value('id'),
            'order_type' => 'Walk-in', 'cash_tendered' => 500,
            'items' => [['product_id' => 'slim', 'quantity' => 2], ['product_id' => 'round', 'quantity' => 1]],
        ])->assertCreated();
        foreach (['SHRINK_SLIM' => 6, 'SHRINK_ROUND' => 1, 'CLEAR_COVER' => 3] as $name => $qty) {
            $item = InventoryItem::where('item_name', $name)->firstOrFail();
            $this->assertSame($before[$name] - $qty, $item->stock_on_hand);
            $this->assertDatabaseHas('stock_movements', ['inventory_item_id' => $item->id,
                'type' => 'sale', 'qty' => -$qty, 'user_id' => $cashier->id,
                'transaction_id' => $response->json('transaction.id')]);
        }
    }

    public function test_u_s57_missing_cover_rolls_back_sales_and_audit(): void
    {
        InventoryItem::where('item_name', 'CLEAR_COVER')->update(['stock_on_hand' => 0]);
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
        $item = InventoryItem::where('item_name', 'CLEAR_COVER')->firstOrFail();
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
        $item = InventoryItem::where('item_name', 'CLEAR_COVER')->firstOrFail();
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
        $item = InventoryItem::where('item_name', 'SHRINK_SLIM')->firstOrFail();
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
        $item = InventoryItem::where('item_name', 'CLEAR_COVER')->firstOrFail();
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
        foreach (['SHRINK_SLIM' => 3, 'SHRINK_ROUND' => 1, 'CLEAR_COVER' => 2] as $name => $qty) {
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
        $customer = Customer::where('name', '!=', 'Walk-in Guest')->firstOrFail();
        $customer->update(['debt_balance' => 100]);
        $this->actingAs(User::where('username', 'cashier')->firstOrFail())
            ->postJson('/api/customers/'.$customer->id.'/settle', ['submission_key' => (string) Str::uuid(), 'amount' => 30])
            ->assertOk()->assertJsonPath('transaction.balance_after', 70);
    }

    public function test_u_s16_today_filter_excludes_older_transactions(): void
    {
        $old = Transaction::firstOrFail();
        $old->update(['transaction_date' => today()->subDay()]);
        $this->actingAs(User::where('username', 'cashier')->firstOrFail())
            ->getJson('/api/transactions?days=0')->assertOk()->assertJsonMissing(['no' => $old->receipt_number]);
    }

    public function test_u_s63_history_is_paginated_and_owner_only(): void
    {
        $owner = User::where('username', 'admin')->firstOrFail();
        $item = InventoryItem::where('item_name', 'CLEAR_COVER')->firstOrFail();
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
        $item = InventoryItem::where('item_name', '5-Gal Replacement Slim Jugs')->firstOrFail();
        $before = $item->stock_on_hand;
        $this->actingAs(User::where('username', 'admin')->firstOrFail())
            ->postJson('/api/inventory/'.$item->id.'/movements', ['type' => 'damage', 'qty' => -1, 'reason' => 'damage', 'notes' => 'Cracked new retail jug'])
            ->assertCreated();
        $this->assertSame($before - 1, $item->fresh()->stock_on_hand);
        $this->assertDatabaseHas('stock_movements', ['inventory_item_id' => $item->id, 'type' => 'damage', 'qty' => -1]);
    }

    public function test_report_pages_include_sales_beyond_the_recent_cache(): void
    {
        $template = Transaction::firstOrFail()->getAttributes();
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
}
