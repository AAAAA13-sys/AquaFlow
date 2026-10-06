<?php

namespace Tests\Feature;

use App\Models\ContainerCustodyLog;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\ProductionQueueItem;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->cashier = User::query()->where('username', 'cashier')->firstOrFail();
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->walkInId(),
            'order_type' => 'Walk-in',
            'payment_method' => 'Cash',
            'cash_tendered' => 100,
            'items' => [['product_id' => 'slim', 'quantity' => 2]],
        ], $overrides);
    }

    private function walkInId(): int
    {
        return (int) Customer::query()->where('name', 'Walk-in Guest')->value('id');
    }

    private function stock(string $item): int
    {
        return (int) InventoryItem::query()->where('item_name', $item)->value('stock_on_hand');
    }

    // ---------- Pricing: 12% VAT is inclusive ----------

    public function test_u_s07_u_s11_the_shelf_price_is_the_gross_total(): void
    {
        $response = $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('totals.total', 70)
            ->assertJsonPath('totals.cash_change', 30)
            ->assertJsonPath('transaction.items.0.quantity', 2)
            ->assertJsonPath('transaction.items.0.line_total', 70)
            ->assertJsonPath('transaction.cash_tendered', 100)
            ->assertJsonPath('transaction.cash_change', 30)
            ->assertJsonPath('transaction.by', $this->cashier->name);
    }

    public function test_u_s14_vat_is_extracted_from_the_total_not_added_on_top(): void
    {
        // One refill at P35.00 -> vatable 31.25, VAT 3.75, total 35.00
        $response = $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload([
            'cash_tendered' => 50,
            'items' => [['product_id' => 'slim', 'quantity' => 1]],
        ]));

        $response->assertCreated()
            ->assertJsonPath('totals.total', 35)
            ->assertJsonPath('totals.vatable', 31.25)
            ->assertJsonPath('totals.vat', 3.75);
    }

    public function test_u_s09_vatable_and_vat_always_reconcile_to_the_total(): void
    {
        $response = $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload([
            'cash_tendered' => 1000,
            'items' => [
                ['product_id' => 'slim', 'quantity' => 3],
                ['product_id' => 'round', 'quantity' => 1],
                ['product_id' => 'newS', 'quantity' => 1],
                ['product_id' => 'newR', 'quantity' => 2],
            ],
        ]));

        $response->assertCreated();

        // 4 refills (P35) + 3 new jugs (P250) = P890 gross.
        $this->assertSame(890.0, (float) $response->json('totals.total'));

        $totals = $response->json('totals');
        $this->assertEqualsWithDelta($totals['total'], $totals['vatable'] + $totals['vat'], 0.01);
    }

    public function test_the_stored_receipt_splits_vatable_sales_and_vat(): void
    {
        $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload([
            'cash_tendered' => 50,
            'items' => [['product_id' => 'slim', 'quantity' => 1]],
        ]))->assertCreated();

        $this->assertDatabaseHas('transactions', [
            'subtotal_amount' => 31.25,
            'vat_amount' => 3.75,
            'total_amount' => 35.00,
            'auto_discount' => 0,
            'manual_discount' => 0,
        ]);
    }

    public function test_no_discounts_are_supported(): void
    {
        $response = $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload([
            'manual_discount' => 50,
            'auto_discount' => 25,
        ]));

        $response->assertCreated();
        $this->assertSame(70.0, (float) $response->json('totals.total'));
        $this->assertArrayNotHasKey('manual_discount', $response->json('totals'));
    }

    // ---------- Containers: the station owns none ----------

    public function test_u_s17_u_s19_u_s20_a_refill_creates_no_custody_liability(): void
    {
        $customer = Customer::query()->where('name', 'Santos Family')->firstOrFail();
        $issued = (int) $customer->issued_slim;
        $returned = (int) $customer->returned_slim;
        $logs = ContainerCustodyLog::query()->count();

        $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload([
            'customer_id' => $customer->id,
            'items' => [['product_id' => 'slim', 'quantity' => 2]],
        ]))->assertCreated();

        // A refill is the customer's own jug going home full: no liability
        // accrues and no custody movement is written.
        $customer->refresh();
        $this->assertSame($issued, (int) $customer->issued_slim);
        $this->assertSame($returned, (int) $customer->returned_slim);
        $this->assertSame($logs, ContainerCustodyLog::query()->count());
    }

    public function test_u_s18_u_s33_a_new_jug_is_retail_merchandise_that_depletes_stock(): void
    {
        $before = $this->stock('5-Gal Replacement Slim Jugs');

        $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload([
            'cash_tendered' => 500,
            'items' => [['product_id' => 'newS', 'quantity' => 2]],
        ]))->assertCreated();

        $this->assertSame($before - 2, $this->stock('5-Gal Replacement Slim Jugs'));
    }

    public function test_a_new_jug_cannot_be_delivered(): void
    {
        $customer = Customer::query()->where('name', 'Reyes Store')->firstOrFail();

        $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload([
            'customer_id' => $customer->id,
            'order_type' => 'Delivery',
            'cash_tendered' => 300,
            'items' => [['product_id' => 'newR', 'quantity' => 1]],
        ]))->assertStatus(422);
    }

    public function test_the_till_offers_exactly_the_four_counter_items(): void
    {
        $response = $this->actingAs($this->cashier)->getJson('/api/bootstrap');

        $response->assertOk();

        $till = collect($response->json('products'))->where('sold_at_pos', true);

        // Slim refill P35, Round refill P35, New Slim jug P250, New Round jug P250.
        $this->assertSame(['newR', 'newS', 'round', 'slim'], $till->pluck('id')->sort()->values()->all());

        $prices = $till->pluck('price', 'id')->all();
        $this->assertSame(35.0, (float) $prices['slim']);
        $this->assertSame(35.0, (float) $prices['round']);
        $this->assertSame(250.0, (float) $prices['newS']);
        $this->assertSame(250.0, (float) $prices['newR']);
    }

    public function test_u_s10_owner_managed_supplies_are_rejected_at_the_till(): void
    {
        foreach (['caps', 'seals', 'soap'] as $productId) {
            $this->actingAs($this->cashier)
                ->postJson('/api/transactions', $this->payload([
                    'cash_tendered' => 500,
                    'items' => [['product_id' => $productId, 'quantity' => 1]],
                ]))
                ->assertStatus(422);
        }
    }

    // ---------- Inventory ----------

    public function test_u_s28_every_refilled_gallon_consumes_one_cap_and_one_seal(): void
    {
        $caps = $this->stock('Non-Spill Caps');
        $seals = $this->stock('Heat Shrink Seals');

        $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload())->assertCreated();

        $this->assertSame($caps - 2, $this->stock('Non-Spill Caps'));
        $this->assertSame($seals - 2, $this->stock('Heat Shrink Seals'));
    }

    public function test_a_new_jug_does_not_consume_caps(): void
    {
        $caps = $this->stock('Non-Spill Caps');

        $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload([
            'cash_tendered' => 300,
            'items' => [['product_id' => 'newS', 'quantity' => 1]],
        ]))->assertCreated();

        $this->assertSame($caps, $this->stock('Non-Spill Caps'));
    }

    // ---------- Catalogue & validation ----------

    public function test_brand_new_jugs_are_flagged_walk_in_only_with_live_stock(): void
    {
        $response = $this->actingAs($this->cashier)->getJson('/api/bootstrap');

        $newS = collect($response->json('products'))->firstWhere('id', 'newS');
        $slim = collect($response->json('products'))->firstWhere('id', 'slim');

        $this->assertTrue($newS['walk_in_only']);
        $this->assertIsInt($newS['stock']);

        // A refill consumes no container, so it carries no stock figure.
        $this->assertFalse($slim['walk_in_only']);
        $this->assertNull($slim['stock']);
    }

    public function test_unknown_product_is_rejected(): void
    {
        $this->actingAs($this->cashier)
            ->postJson('/api/transactions', $this->payload([
                'items' => [['product_id' => 'hacked', 'quantity' => 1]],
            ]))
            ->assertStatus(422);
    }

    public function test_cash_underpayment_is_rejected(): void
    {
        $this->actingAs($this->cashier)
            ->postJson('/api/transactions', $this->payload(['cash_tendered' => 10]))
            ->assertStatus(422);
    }

    public function test_empty_cart_is_rejected(): void
    {
        $this->actingAs($this->cashier)
            ->postJson('/api/transactions', $this->payload(['items' => []]))
            ->assertStatus(422);
    }

    public function test_a_walk_in_can_never_accrue_debt(): void
    {
        $guest = Customer::query()->where('name', 'Walk-in Guest')->firstOrFail();
        $before = (float) $guest->debt_balance;

        // Even if a client asks to charge it, a walk-in is cash.
        $response = $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload([
            'payment_method' => 'Account',
        ]));

        $response->assertCreated();
        $this->assertSame('Cash', $response->json('transaction.pay'));
        $this->assertSame($before, (float) $guest->refresh()->debt_balance);
    }

    public function test_a_walk_in_cannot_request_delivery(): void
    {
        $this->actingAs($this->cashier)
            ->postJson('/api/transactions', $this->payload(['order_type' => 'Delivery']))
            ->assertStatus(422);
    }

    // ---------- Payment methods ----------

    public function test_the_payment_method_is_derived_and_never_taken_from_the_client(): void
    {
        // A walk-in is always cash, whatever the client claims.
        $walkIn = $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload([
            'payment_method' => 'GCash',
        ]));
        $walkIn->assertCreated();
        $this->assertSame('Cash', $walkIn->json('transaction.pay'));
        $this->assertDatabaseHas('transactions', [
            'receipt_number' => $walkIn->json('transaction.no'),
            'payment_method' => 'Cash',
        ]);
    }

    public function test_u_s13_a_delivery_is_charged_to_the_account_automatically(): void
    {
        $customer = Customer::query()->where('name', 'Santos Family')->firstOrFail();
        $before = (float) $customer->debt_balance;

        $response = $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload([
            'customer_id' => $customer->id,
            'order_type' => 'Delivery',
            'items' => [['product_id' => 'slim', 'quantity' => 4]],
        ]));

        $response->assertCreated();
        $this->assertSame('Account', $response->json('transaction.pay'));
        $this->assertSame($before + 140.0, (float) $customer->refresh()->debt_balance);
    }

    public function test_a_delivery_records_no_cash_tender_or_change(): void
    {
        $customer = Customer::query()->where('name', 'Reyes Store')->firstOrFail();

        $response = $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload([
            'customer_id' => $customer->id,
            'order_type' => 'Delivery',
            'cash_tendered' => 500,
        ]));

        $response->assertCreated();
        $this->assertSame(0.0, (float) $response->json('totals.cash_tendered'));
        $this->assertSame(0.0, (float) $response->json('totals.cash_change'));
    }

    // ---------- Receipt & queue ----------

    public function test_a_receipt_number_is_assigned_and_queued(): void
    {
        $response = $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload());
        $response->assertCreated();

        $receipt = $response->json('transaction.no');
        $this->assertMatchesRegularExpression('/^OR-\d+$/', $receipt);
        $this->assertDatabaseHas('production_queue', ['receipt_number' => $receipt, 'is_completed' => 0]);
    }

    public function test_selling_only_new_jugs_does_not_queue_production(): void
    {
        $before = ProductionQueueItem::query()->count();

        // A brand-new jug is merchandise, not a refill, so nothing is queued to
        // be washed and filled.
        $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload([
            'cash_tendered' => 300,
            'items' => [['product_id' => 'newS', 'quantity' => 1]],
        ]))->assertCreated();

        $this->assertSame($before, ProductionQueueItem::query()->count());
    }

    public function test_receipt_numbers_are_unique_across_sales(): void
    {
        $first = $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload())->json('transaction.no');
        $second = $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload())->json('transaction.no');

        $this->assertNotSame($first, $second);
    }

    public function test_sales_cannot_be_posted_without_authentication(): void
    {
        $before = Transaction::query()->count();

        $this->postJson('/api/transactions', $this->payload())->assertStatus(401);

        $this->assertSame($before, Transaction::query()->count());
    }

    public function test_history_exposes_the_vat_split(): void
    {
        $created = $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload());
        $created->assertCreated();

        $receipt = $created->json('transaction.no');

        $response = $this->actingAs($this->cashier)->getJson('/api/transactions?days=1');
        $response->assertOk();

        // The log is newest-first and also carries settlements, so locate this
        // receipt rather than assuming row 0.
        $row = collect($response->json('transactions'))->firstWhere('no', $receipt);
        $this->assertNotNull($row, 'the sale should appear in the history');

        $this->assertArrayHasKey('vatable', $row);
        $this->assertArrayHasKey('vat', $row);
        $this->assertEqualsWithDelta((float) $row['total'], (float) $row['vatable'] + (float) $row['vat'], 0.01);
    }

    public function test_a_debt_settlement_carries_no_vat_split(): void
    {
        // Collecting a receivable is not a new taxable sale.
        $response = $this->actingAs($this->cashier)->getJson('/api/transactions?days=1&type=Debt Payment');

        $response->assertOk();

        foreach ($response->json('transactions') as $row) {
            $this->assertSame(0.0, (float) $row['vatable']);
            $this->assertSame(0.0, (float) $row['vat']);
            $this->assertGreaterThan(0.0, (float) $row['total']);
        }
    }

    public function test_u_s24_u_s26_u_s27_queue_stage_can_be_advanced(): void
    {
        $receipt = $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload())->json('transaction.no');

        $item = ProductionQueueItem::query()->where('receipt_number', $receipt)->firstOrFail();
        $this->assertSame(0, $item->stage);

        $this->actingAs(User::where('username', 'admin')->firstOrFail())
            ->postJson('/api/queue/'.$item->id.'/advance')
            ->assertOk()
            ->assertJsonPath('item.stage', 1);
    }

    public function test_duplicate_cart_entries_have_identical_priced_and_stored_lines(): void
    {
        $response = $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload([
            'cash_tendered' => 1000,
            'items' => [
                ['product_id' => 'round', 'quantity' => 2],
                ['product_id' => 'slim', 'quantity' => 1],
                ['product_id' => 'round', 'quantity' => 3],
            ],
        ]))->assertCreated();

        $stored = Transaction::query()->findOrFail($response->json('transaction.id'))->items()->orderBy('id')->get();
        $lines = $response->json('totals.lines');
        $this->assertCount(2, $stored);
        foreach ($stored as $index => $item) {
            // JSON decodes whole-number prices as integers; model casts are floats.
            $lines[$index]['unit_price'] = (float) $lines[$index]['unit_price'];
            $lines[$index]['line_total'] = (float) $lines[$index]['line_total'];
            $this->assertSame($lines[$index], $item->only(['product_id', 'item_name', 'quantity', 'unit_price', 'line_total']));
        }
    }

    public function test_receipts_continue_after_the_highest_queued_receipt(): void
    {
        ProductionQueueItem::query()->create([
            'receipt_number' => 'OR-9000', 'customer_name' => 'Queue Customer',
            'stage' => 0, 'elapsed_minutes' => 0, 'items_description' => '1S',
            'order_type' => 'Walk-in', 'is_completed' => false,
        ]);

        $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload())
            ->assertCreated()->assertJsonPath('transaction.no', 'OR-9001');
    }

    public function test_u_s15_insufficient_consumables_roll_back_the_entire_delivery(): void
    {
        InventoryItem::query()->where('item_name', 'Heat Shrink Seals')->update(['stock_on_hand' => 1]);
        $customer = Customer::query()->where('name', '!=', 'Walk-in Guest')->firstOrFail();
        $beforeCustomer = $customer->getAttributes();
        $beforeStock = InventoryItem::query()->pluck('stock_on_hand', 'id')->all();
        $transactions = Transaction::count();
        $queue = ProductionQueueItem::count();
        $items = TransactionItem::count();

        $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload([
            'customer_id' => $customer->id,
            'order_type' => 'Delivery',
        ]))->assertUnprocessable()->assertJsonValidationErrors('items')
            ->assertJsonPath('errors.items.0', 'Insufficient stock: Heat Shrink Seals is short by 1 pcs.');

        $this->assertSame($transactions, Transaction::count());
        $this->assertSame($items, TransactionItem::count());
        $this->assertSame($queue, ProductionQueueItem::count());
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame($beforeCustomer, $customer->fresh()->getAttributes());
        $this->assertSame($beforeStock, InventoryItem::query()->pluck('stock_on_hand', 'id')->all());
    }

    public function test_u_s15_insufficient_new_jug_stock_rejects_the_sale(): void
    {
        InventoryItem::query()->where('item_name', '5-Gal Replacement Slim Jugs')->update(['stock_on_hand' => 1]);
        $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload([
            'cash_tendered' => 500,
            'items' => [['product_id' => 'newS', 'quantity' => 2]],
        ]))->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertSame(1, $this->stock('5-Gal Replacement Slim Jugs'));
    }

    public function test_u_s15_exact_available_stock_can_be_sold(): void
    {
        InventoryItem::query()->where('item_name', 'Heat Shrink Seals')->update(['stock_on_hand' => 2]);
        $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload())->assertCreated();
        $this->assertSame(0, $this->stock('Heat Shrink Seals'));
    }

    public function test_u_s25_unfinished_orders_from_yesterday_remain_visible(): void
    {
        ProductionQueueItem::create(['receipt_number' => 'OR-OLD', 'customer_name' => 'Waiting',
            'stage' => 1, 'elapsed_minutes' => 0, 'items_description' => '1S', 'order_type' => 'Delivery',
            'is_completed' => false])->forceFill(['created_at' => now()->subDay()])->save();
        $this->actingAs($this->cashier)->getJson('/api/queue')->assertOk()
            ->assertJsonFragment(['no' => 'OR-OLD']);
    }
}
