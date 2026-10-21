<?php

namespace Tests\Feature;

use App\Models\ContainerCustodyLog;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductionQueueItem;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\CashShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesAndCheckoutFeatureTest extends TestCase
{
    use RefreshDatabase;

    private ?User $cashier = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->cashier = User::where('username', 'cashier')->first();
        Customer::where('name', 'Santos Family')->update(['debt_balance' => 150]);
        Customer::where('name', 'Dela Cruz Residence')->update(['issued_round' => 4, 'returned_round' => 3]);
        Customer::where('name', 'San Miguel Commercial Canteen')->update(['issued_slim' => 45, 'returned_slim' => 30]);

        $retryTests = [
            'test_sale_retry_returns_original_receipt_without_deducting_stock_again',
            'test_a_cashier_payment_retry_returns_the_same_request_without_collecting_again',
            'test_failed_sale_can_be_retried_without_a_partial_submission',
            'test_submission_keys_are_required_and_separate_keys_allow_identical_sales',
            'test_delivery_retry_preserves_ledger_balance_and_operator_keys_are_isolated',
        ];

        if (in_array($this->name(), $retryTests, true)) {
            $this->actingAs($this->cashier);
        }
    }

    // --- From CheckoutTest.php ---

    

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'submission_key' => (string) Str::uuid(),
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
        $before = $this->stock('5 Gallon Slim Jug');

        $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload([
            'cash_tendered' => 500,
            'items' => [['product_id' => 'newS', 'quantity' => 2]],
        ]))->assertCreated();

        $this->assertSame($before - 2, $this->stock('5 Gallon Slim Jug'));
    }

    public function test_a_new_jug_delivery_charges_the_ledger_and_creates_fulfillment(): void
    {
        $customer = Customer::query()->where('name', 'Reyes Store')->firstOrFail();
        $customer->update(['debt_balance' => 500]);

        $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload([
            'customer_id' => $customer->id,
            'order_type' => 'Delivery',
            'cash_tendered' => 300,
            'items' => [['product_id' => 'newR', 'quantity' => 1]],
        ]))->assertCreated();
        $this->assertEquals(750, $customer->fresh()->debt_balance);
        $this->assertDatabaseHas('production_queue', ['customer_name' => $customer->name, 'order_type' => 'Delivery', 'is_completed' => false]);
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
        $cover = $this->stock('Clear Cover');
        $seal = $this->stock('Shrink Seal (Circle Top Slim)');

        $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload())->assertCreated();

        $this->assertSame($cover - 2, $this->stock('Clear Cover'));
        $this->assertSame($seal - 2, $this->stock('Shrink Seal (Circle Top Slim)'));
    }

    public function test_a_new_jug_does_not_consume_caps(): void
    {
        $jugStock = $this->stock('5 Gallon Slim Jug');

        $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload([
            'cash_tendered' => 300,
            'items' => [['product_id' => 'newS', 'quantity' => 1]],
        ]))->assertCreated();

        $this->assertSame($jugStock - 1, $this->stock('5 Gallon Slim Jug'));
    }

    // ---------- Catalogue & validation ----------

    public function test_brand_new_jugs_allow_delivery_with_live_stock(): void
    {
        $response = $this->actingAs($this->cashier)->getJson('/api/bootstrap');

        $newS = collect($response->json('products'))->firstWhere('id', 'newS');
        $slim = collect($response->json('products'))->firstWhere('id', 'slim');

        $this->assertFalse($newS['walk_in_only']);
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
            ->assertForbidden();
        $refiller = User::create(['username' => 'qa_checkout_refiller', 'name' => 'Refiller', 'role' => 'refiller', 'employee_id' => Employee::where('name', 'Carla Mendoza')->firstOrFail()->id, 'password' => 'Refill123', 'is_active' => true]);
        $this->actingAs($refiller)->postJson('/api/employee/orders/'.$item->id, ['action' => 'ready', 'version' => 0, 'submission_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('order.status', 'ready');
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
        InventoryItem::query()->where('item_name', 'Clear Cover')->update(['stock_on_hand' => 1]);
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
            ->assertJsonPath('errors.items.0', 'Insufficient stock: Clear Cover is short by 1 pcs.');

        $this->assertSame($transactions, Transaction::count());
        $this->assertSame($items, TransactionItem::count());
        $this->assertSame($queue, ProductionQueueItem::count());
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame($beforeCustomer, $customer->fresh()->getAttributes());
        $this->assertSame($beforeStock, InventoryItem::query()->pluck('stock_on_hand', 'id')->all());
    }

    public function test_u_s15_insufficient_new_jug_stock_rejects_the_sale(): void
    {
        InventoryItem::query()->where('item_name', '5 Gallon Slim Jug')->update(['stock_on_hand' => 1]);
        $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload([
            'cash_tendered' => 500,
            'items' => [['product_id' => 'newS', 'quantity' => 2]],
        ]))->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertSame(1, $this->stock('5 Gallon Slim Jug'));
    }

    public function test_u_s15_exact_available_stock_can_be_sold(): void
    {
        InventoryItem::query()->where('item_name', 'Clear Cover')->update(['stock_on_hand' => 2]);
        $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload())->assertCreated();
        $this->assertSame(0, $this->stock('Clear Cover'));
    }

    public function test_u_s25_unfinished_orders_from_yesterday_remain_visible(): void
    {
        ProductionQueueItem::create(['receipt_number' => 'OR-OLD', 'customer_name' => 'Waiting',
            'stage' => 1, 'elapsed_minutes' => 0, 'items_description' => '1S', 'order_type' => 'Delivery',
            'is_completed' => false])->forceFill(['created_at' => now()->subDay()])->save();
        $this->actingAs($this->cashier)->getJson('/api/queue')->assertOk()
            ->assertJsonFragment(['no' => 'OR-OLD']);
    }

    public function test_historical_guest_cannot_accrue_delivery_debt_after_upgrade(): void
    {
        $guest = Customer::create(['name' => 'Walk-in Guest', 'address' => '-', 'contact' => '-']);
        $this->actingAs($this->cashier)->postJson('/api/transactions', $this->payload([
            'customer_id' => $guest->id, 'order_type' => 'Delivery',
        ]))->assertUnprocessable();
        $this->assertEquals(0, $guest->fresh()->debt_balance);
    }

    // --- From CartAvailabilityTest.php ---
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
        $cover = InventoryItem::where('item_name', 'Clear Cover')->firstOrFail();
        $cover->update(['stock_on_hand' => 3]);
        $this->actingAs(User::where('username', 'cashier')->firstOrFail())
            ->postJson('/api/cart/availability', ['items' => [['product_id' => 'slim', 'quantity' => 2]]])
            ->assertOk()->assertJsonPath('valid', true)->assertJsonPath('additional.round', 1);
        $this->assertSame(3, $cover->fresh()->stock_on_hand);
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

    // --- From CustomerTest.php ---

    

    public function test_customers_can_be_listed_and_searched(): void
    {
        $this->actingAs($this->cashier)
            ->getJson('/api/customers')
            ->assertOk()
            ->assertJsonCount(10, 'customers');

        $this->actingAs($this->cashier)
            ->getJson('/api/customers?q=Santos')
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.name', 'Santos Family');
    }

    public function test_customers_can_be_filtered_by_bottles_or_debt(): void
    {
        $withBottles = $this->actingAs($this->cashier)->getJson('/api/customers?filter=bottles')->json('customers');
        $withDebt = $this->actingAs($this->cashier)->getJson('/api/customers?filter=cash')->json('customers');

        $this->assertNotEmpty($withBottles);
        $this->assertNotEmpty($withDebt);

        foreach ($withDebt as $customer) {
            $this->assertGreaterThan(0, $customer['debt']);
        }
    }

    public function test_a_customer_can_be_registered(): void
    {
        $response = $this->actingAs($this->cashier)->postJson('/api/customers', [
            'name' => 'New Walk-in Customer',
            'address' => 'Block 1 Lot 2',
            'contact' => '0900-111-2222',
        ]);

        $response->assertCreated()
            ->assertJsonPath('customer.name', 'New Walk-in Customer')
            ->assertJsonPath('customer.debt', 0);

        $this->assertDatabaseHas('customers', ['name' => 'New Walk-in Customer']);
    }

    public function test_registration_requires_a_name(): void
    {
        $this->actingAs($this->cashier)
            ->postJson('/api/customers', ['name' => ''])
            ->assertStatus(422);
    }

    public function test_a_partial_debt_payment_is_queued_for_approval_when_a_cashier_records_it(): void
    {
        $customer = Customer::query()->where('name', 'Santos Family')->firstOrFail();
        $before = (float) $customer->debt_balance; // 150.00

        $response = $this->actingAs($this->cashier)
            ->postJson('/api/customers/'.$customer->id.'/settle', ['submission_key' => (string) Str::uuid(), 'amount' => 100]);

        // A cashier's collection waits for a manager: the ledger has not moved.
        $response->assertStatus(202)
            ->assertJsonPath('pending_approval', true)
            ->assertJsonPath('applied', 0);

        $this->assertEqualsWithDelta($before, (float) $customer->fresh()->debt_balance, 0.001);
        // The cash is held so it cannot be collected twice.
        $this->assertEqualsWithDelta(100, (float) $customer->fresh()->settlement_pending_amount, 0.001);
        $this->assertDatabaseHas('approval_requests', ['type' => 'settlement.create', 'state' => 'pending']);
    }

    public function test_a_partial_debt_payment_reduces_the_balance_when_a_manager_collects_it(): void
    {
        $customer = Customer::query()->where('name', 'Santos Family')->firstOrFail();
        $before = (float) $customer->debt_balance; // 150.00
        $manager = User::create([
            'name' => 'Station Manager', 'username' => 'settle_manager',
            'email' => 'settle_manager@example.com', 'password' => 'Private123!',
            'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);

        // A manager holds cash authority, so this posts directly.
        $response = $this->actingAs($manager)
            ->postJson('/api/customers/'.$customer->id.'/settle', ['submission_key' => (string) Str::uuid(), 'amount' => 100]);

        $response->assertOk()->assertJsonPath('applied', 100)->assertJsonPath('pending_approval', false);

        // Whole-number floats serialise as JSON integers, so compare numerically.
        $this->assertEqualsWithDelta($before - 100, (float) $response->json('customer.debt'), 0.001);
    }

    public function test_a_payment_never_exceeds_the_outstanding_balance(): void
    {
        $customer = Customer::query()->where('name', 'Santos Family')->firstOrFail();

        $response = $this->actingAs($this->cashier)
            ->postJson('/api/customers/'.$customer->id.'/settle', ['submission_key' => (string) Str::uuid(), 'amount' => 99999]);

        $response->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertEquals(150, $customer->refresh()->debt_balance);
    }

    public function test_collection_rejects_fractional_cents(): void
    {
        $customer = Customer::where('name', 'Santos Family')->firstOrFail();
        $this->actingAs($this->cashier)->postJson('/api/customers/'.$customer->id.'/settle', [
            'submission_key' => (string) Str::uuid(), 'amount' => 1.001,
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertEquals(150, $customer->refresh()->debt_balance);
    }

    public function test_customer_name_requires_letters_only_without_numbers(): void
    {
        $this->actingAs($this->cashier)->postJson('/api/customers', ['name' => '1234'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($this->cashier)->postJson('/api/customers', ['name' => 'Block 6 Residents'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($this->cashier)->postJson('/api/customers', ['name' => 'Juan Dela Cruz', 'contact' => '09123456789'])
            ->assertCreated();
    }

    public function test_paying_a_settled_account_is_rejected(): void
    {
        $customer = Customer::query()->where('name', 'Santos Family')->firstOrFail();
        $customer->update(['debt_balance' => 0]);

        $this->actingAs($this->cashier)
            ->postJson('/api/customers/'.$customer->id.'/settle', ['submission_key' => (string) Str::uuid(), 'amount' => 10])
            ->assertStatus(422);
    }

    public function test_a_bottle_return_clears_part_of_the_custody_balance(): void
    {
        // Dela Cruz Residence: issued 4 round, returned 3 -> one pending.
        $customer = Customer::query()->where('name', 'Dela Cruz Residence')->firstOrFail();
        $this->assertSame(1, $customer->pendingRound());

        $response = $this->actingAs($this->cashier)
            ->postJson('/api/customers/'.$customer->id.'/returns', ['kind' => 'round']);

        $response->assertStatus(410);
        $this->assertSame(1, $customer->refresh()->pendingRound());
    }

    public function test_a_return_with_no_pending_bottles_is_rejected(): void
    {
        $customer = Customer::query()->where('name', 'Dela Cruz Residence')->firstOrFail();

        $this->actingAs($this->cashier)
            ->postJson('/api/customers/'.$customer->id.'/returns', ['kind' => 'slim'])
            ->assertStatus(410);
    }

    public function test_a_customer_detail_drawer_payload_has_pending_counts(): void
    {
        $response = $this->actingAs($this->cashier)->getJson('/api/customers?q=San Miguel');

        $response->assertOk();
        $customer = $response->json('customers.0');

        $this->assertSame(15, $customer['pending']['slim']); // 45 issued - 30 returned
        $this->assertSame(0, $customer['pending']['round']);
    }

    public function test_unknown_customer_returns_404(): void
    {
        $this->actingAs($this->cashier)
            ->postJson('/api/customers/99999/settle', ['submission_key' => (string) Str::uuid(), 'amount' => 10])
            ->assertStatus(404);
    }

    // --- From CashReconciliationTest.php ---
    public function test_drawer_close_is_scoped_replayable_and_blocks_new_sales(): void
    {
        $this->seed();
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $this->actingAs($cashier)->get('/cashier/cash')->assertOk()->assertSee('cashOpenForm', false)->assertSee('cashCloseForm', false);
        $id = $this->postJson('/api/cash-shift/open', ['opening_float'=>200, 'opening_note'=>'Shift opening'])->assertOk()->json('shift.id');
        $payload = ['submission_key'=>(string) Str::uuid(), 'customer_id'=>Customer::where('name','Walk-in Guest')->value('id'), 'order_type'=>'Walk-in', 'cash_tendered'=>100, 'items'=>[['product_id'=>'slim','quantity'=>1]]];
        $receipt = $this->postJson('/api/transactions',$payload)->assertCreated()->json('transaction.id');
        $expected = $this->getJson('/api/cash-shift')->assertOk()->json('shift.expected_cash');
        $this->postJson('/api/cash-shift/close',['shift_id'=>$id,'expected_cash'=>200,'counted_cash'=>200,'note'=>'Counted earlier'])->assertUnprocessable()->assertJsonValidationErrors('expected_cash');
        $this->postJson('/api/cash-shift/close',['shift_id'=>$id,'expected_cash'=>$expected,'counted_cash'=>$expected-10])->assertUnprocessable()->assertJsonValidationErrors('note');
        $close = ['shift_id'=>$id,'expected_cash'=>$expected,'counted_cash'=>$expected];
        $this->postJson('/api/cash-shift/close',$close)->assertOk();
        $this->postJson('/api/cash-shift/close',$close)->assertOk();
        $stock = InventoryItem::pluck('stock_on_hand','id')->all();
        $this->postJson('/api/transactions',$payload)->assertCreated()->assertJsonPath('transaction.id',$receipt);
        $payload['submission_key']=(string) Str::uuid();
        $this->postJson('/api/transactions',$payload)->assertUnprocessable()->assertJsonValidationErrors('drawer');
        $this->assertSame($stock,InventoryItem::pluck('stock_on_hand','id')->all());
        $this->getJson('/api/cash-shift/overview')->assertForbidden();
        $manager = User::create(['name'=>'Manager','username'=>'drawer_manager','email'=>'manager@example.com','password'=>'Private123!','role'=>'admin','is_active'=>true]);
        $this->actingAs($manager)->get('/admin/cash')->assertOk();
        $this->getJson('/api/cash-shift/overview')->assertOk()->assertJsonPath('shifts.0.expected_cash',$expected);
        $this->getJson('/api/cash-shift')->assertOk()->assertJsonCount(0,'history');
        $this->postJson('/api/cash-shift/close',$close)->assertUnprocessable();
    }
    public function test_unapproved_collections_prevent_closing_and_midnight_drawer_is_recoverable(): void
    {
        $this->seed();
        $cashier = User::where('role','cashier')->firstOrFail();
        $customer = Customer::where('name','Santos Family')->firstOrFail();
        $customer->update(['debt_balance'=>100]);
        $id = $this->actingAs($cashier)->postJson('/api/cash-shift/open',['opening_float'=>200, 'opening_note'=>'Shift opening'])->assertOk()->json('shift.id');
        $this->postJson('/api/customers/'.$customer->id.'/settle',['amount'=>50,'submission_key'=>(string) Str::uuid()])->assertStatus(202);
        $this->getJson('/api/cash-shift')->assertOk()->assertJsonPath('shift.pending_collections',50);
        $this->postJson('/api/cash-shift/close',['shift_id'=>$id,'expected_cash'=>200,'counted_cash'=>200])->assertUnprocessable()->assertJsonValidationErrors('drawer');
        $this->travel(1)->days();
        $this->getJson('/api/cash-shift')->assertOk()->assertJsonPath('shift.id',$id);
        $this->postJson('/api/cash-shift/open',['opening_float'=>100, 'opening_note'=>'Shift opening'])->assertConflict();
        $this->assertDatabaseHas('cash_shifts',['id'=>$id,'closed_at'=>null]);
        $approval=\App\Models\ApprovalRequest::where('type','settlement.create')->firstOrFail();
        $manager=User::create(['name'=>'Manager','username'=>'midnight_manager','email'=>'midnight@example.com','password'=>'Private123!','role'=>'admin','is_active'=>true]);
        $this->actingAs($manager)->postJson('/api/approvals/'.$approval->id.'/decision',['decision'=>'approve','submission_key'=>(string) Str::uuid()])->assertOk();
        $this->actingAs($cashier)->getJson('/api/cash-shift')->assertOk()->assertJsonPath('shift.expected_cash',250);
        $this->postJson('/api/cash-shift/close',['shift_id'=>$id,'expected_cash'=>250,'counted_cash'=>250])->assertOk();
        $this->postJson('/api/cash-shift/open',['opening_float'=>100, 'opening_note'=>'Shift opening'])->assertOk()->assertJsonPath('shift.expected_cash',100);
        $this->travelBack();
    }
    public function test_approved_collection_enters_the_cashiers_drawer_once_and_closed_drawer_rejects_new_proposals(): void
    {
        $this->seed();
        $cashier=User::where('role','cashier')->firstOrFail();
        $customer=Customer::where('name','Santos Family')->firstOrFail();
        $customer->update(['debt_balance'=>100]);
        $id=$this->actingAs($cashier)->postJson('/api/cash-shift/open',['opening_float'=>200, 'opening_note'=>'Shift opening'])->assertOk()->json('shift.id');
        $this->postJson('/api/customers/'.$customer->id.'/settle',['amount'=>50,'submission_key'=>(string) Str::uuid()])->assertStatus(202);
        $approval=\App\Models\ApprovalRequest::where('type','settlement.create')->firstOrFail();
        $manager=User::create(['name'=>'Manager','username'=>'approving_manager','email'=>'approver@example.com','password'=>'Private123!','role'=>'admin','is_active'=>true]);
        $decision=['decision'=>'approve','submission_key'=>(string) Str::uuid()];
        $this->actingAs($manager)->postJson('/api/approvals/'.$approval->id.'/decision',$decision)->assertOk();
        $this->postJson('/api/approvals/'.$approval->id.'/decision',$decision)->assertOk();
        $this->actingAs($cashier)->getJson('/api/cash-shift')->assertOk()->assertJsonPath('shift.expected_cash',250)->assertJsonPath('shift.collections',50)->assertJsonPath('shift.total_sales',0)->assertJsonPath('shift.pending_collections',0);
        $this->postJson('/api/cash-shift/close',['shift_id'=>$id,'expected_cash'=>250,'counted_cash'=>250])->assertOk();
        $this->postJson('/api/customers/'.$customer->id.'/settle',['amount'=>10,'submission_key'=>(string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('drawer');
        $this->assertEquals(50,$customer->fresh()->debt_balance);
        $this->assertEquals(0,$customer->fresh()->settlement_pending_amount);
        $this->assertDatabaseCount('approval_requests',1);
    }

    // --- From TransactionRetryTest.php ---
    private function sale(): array
    {
        return ['submission_key' => (string) Str::uuid(), 'customer_id' => Customer::where('name', 'Walk-in Guest')->value('id'), 'order_type' => 'Walk-in', 'cash_tendered' => 100, 'items' => [['product_id' => 'slim', 'quantity' => 1]]];
    }

    public function test_sale_retry_returns_original_receipt_without_deducting_stock_again(): void
    {
        $payload = $this->sale();
        $first = $this->postJson('/api/transactions', $payload)->assertCreated();
        $stock = InventoryItem::pluck('stock_on_hand', 'id')->all();
        $sales = Transaction::count();
        $movements = DB::table('stock_movements')->count();
        $queue = DB::table('production_queue')->count();
        Product::whereKey('slim')->update(['price' => 99]);
        $this->postJson('/api/transactions', $payload)->assertCreated()->assertJsonPath('transaction.id', $first->json('transaction.id'))->assertJsonPath('totals.total', $first->json('totals.total'));
        $this->assertSame($stock, InventoryItem::pluck('stock_on_hand', 'id')->all());
        $this->assertSame($sales, Transaction::count());
        $this->assertSame($movements, DB::table('stock_movements')->count());
        $this->assertSame($queue, DB::table('production_queue')->count());
        $payload['items'][0]['quantity'] = 2;
        $this->postJson('/api/transactions', $payload)->assertStatus(409);
    }

    public function test_a_manager_payment_retry_does_not_collect_twice_even_after_balance_is_cleared(): void
    {
        // A manager holds cash authority, so this stays the direct ledger path.
        $this->actingAs(User::where('username', 'admin')->firstOrFail());

        $c = Customer::where('name', 'Santos Family')->firstOrFail();
        $c->update(['debt_balance' => 150]);
        $data = ['submission_key' => (string) Str::uuid(), 'amount' => 150];
        $first = $this->postJson('/api/customers/'.$c->id.'/settle', $data)->assertOk();
        $count = Transaction::count();
        $this->postJson('/api/customers/'.$c->id.'/settle', $data)->assertOk()->assertJsonPath('transaction.id', $first->json('transaction.id'))->assertJsonPath('applied', 150);
        $this->assertSame($count, Transaction::count());
        $this->assertEquals(0, $c->fresh()->debt_balance);
        $data['amount'] = 100;
        $this->postJson('/api/customers/'.$c->id.'/settle', $data)->assertStatus(409);
    }

    public function test_a_cashier_payment_retry_returns_the_same_request_without_collecting_again(): void
    {
        // A cashier's collection is a proposal. Re-sending the same key must
        // return the request already raised rather than filing a second one.
        $c = Customer::where('name', 'Santos Family')->firstOrFail();
        $c->update(['debt_balance' => 150]);
        $data = ['submission_key' => (string) Str::uuid(), 'amount' => 150];

        $this->postJson('/api/customers/'.$c->id.'/settle', $data)
            ->assertStatus(202)->assertJsonPath('replayed', false);
        $this->postJson('/api/customers/'.$c->id.'/settle', $data)
            ->assertStatus(202)->assertJsonPath('replayed', true)
            ->assertJsonPath('request.id', \App\Models\ApprovalRequest::firstOrFail()->id);

        $this->assertSame(1, \App\Models\ApprovalRequest::count());
        // Nothing has posted, and the cash stays reserved.
        $this->assertSame(0, Transaction::where('order_type', 'Debt Payment')->count());
        $this->assertEquals(150, $c->fresh()->debt_balance);
        $this->assertEquals(150, $c->fresh()->settlement_pending_amount);
    }

    public function test_failed_sale_can_be_retried_without_a_partial_submission(): void
    {
        $data = $this->sale();
        $cover = InventoryItem::whereIn('item_name', ['Clear Cover', 'CLEAR_COVER'])->firstOrFail();
        $cover->update(['stock_on_hand' => 0]);
        $this->postJson('/api/transactions', $data)->assertUnprocessable();
        $this->assertDatabaseMissing('transactions', ['submission_key' => $data['submission_key']]);
        $cover->update(['stock_on_hand' => 50]);
        $this->postJson('/api/transactions', $data)->assertCreated();
    }

    public function test_submission_keys_are_required_and_separate_keys_allow_identical_sales(): void
    {
        $data = $this->sale();
        unset($data['submission_key']);
        $this->postJson('/api/transactions', $data)->assertUnprocessable()->assertJsonValidationErrors('submission_key');
        $c = Customer::where('name', 'Santos Family')->firstOrFail();
        $this->postJson('/api/customers/'.$c->id.'/settle', ['amount' => 10])->assertUnprocessable()->assertJsonValidationErrors('submission_key');
        $one = $this->postJson('/api/transactions', $this->sale())->assertCreated()->json('transaction.id');
        $two = $this->postJson('/api/transactions', $this->sale())->assertCreated()->json('transaction.id');
        $this->assertNotSame($one,$two);
    }

    public function test_delivery_retry_preserves_ledger_balance_and_operator_keys_are_isolated(): void
    {
        // A cashier makes a delivery sale.
        $customer = Customer::where('name', 'Santos Family')->firstOrFail();
        $payload = array_replace($this->sale(), ['customer_id' => $customer->id, 'order_type' => 'Delivery', 'cash_tendered' => 0]);
        $first = $this->postJson('/api/transactions', $payload)->assertCreated();
        $balance = $customer->fresh()->debt_balance;
        $this->postJson('/api/transactions', $payload)->assertCreated()->assertJsonPath('replayed', true);
        $this->assertEquals($balance, $customer->fresh()->debt_balance);

        // A cashier's collection with that key is a proposal, not a direct
        // write, so it is recorded rather than conflicting with the sale.
        $this->postJson('/api/customers/'.$customer->id.'/settle', ['submission_key' => $payload['submission_key'], 'amount' => 10])->assertStatus(202);
        $this->assertEquals(0, Transaction::where('order_type', 'Debt Payment')->count());

        // A *different* operator reusing the key makes a genuinely separate
        // sale: the keys are scoped per account.
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $second = $this->postJson('/api/transactions', $payload)->assertCreated()->assertJsonPath('replayed', false);
        $this->assertNotSame($first->json('transaction.id'), $second->json('transaction.id'));

        // And that operator's own direct settlement cannot reuse the key.
        $this->postJson('/api/customers/'.$customer->id.'/settle', ['submission_key' => $payload['submission_key'], 'amount' => 10])->assertStatus(409);
    }

    public function test_shift_schedule_verification_and_19_case_punctuality_matrix(): void
    {
        $date = '2026-10-21';
        $tz = 'Asia/Manila';
        \App\Models\SystemSetting::bustShiftPolicyCache();

        // T1: null opened_at -> 'unopened', 0
        $shift = new CashShift(['business_date' => $date, 'opened_at' => null]);
        $this->assertSame('unopened', $shift->openingPunctuality());
        $this->assertSame(0, $shift->openingLateMinutes());

        // T2: 07:29:59 -> 'early', 0
        $shift->opened_at = \Carbon\Carbon::parse("{$date} 07:29:59", $tz);
        $this->assertSame('early', $shift->openingPunctuality());
        $this->assertSame(0, $shift->openingLateMinutes());

        // T3: 07:59:59 -> 'early', 0
        $shift->opened_at = \Carbon\Carbon::parse("{$date} 07:59:59", $tz);
        $this->assertSame('early', $shift->openingPunctuality());
        $this->assertSame(0, $shift->openingLateMinutes());

        // T4: 08:00:00 -> 'on_time', 0
        $shift->opened_at = \Carbon\Carbon::parse("{$date} 08:00:00", $tz);
        $this->assertSame('on_time', $shift->openingPunctuality());
        $this->assertSame(0, $shift->openingLateMinutes());

        // T5: 08:15:00 -> 'on_time', 0
        $shift->opened_at = \Carbon\Carbon::parse("{$date} 08:15:00", $tz);
        $this->assertSame('on_time', $shift->openingPunctuality());
        $this->assertSame(0, $shift->openingLateMinutes());

        // T6: 08:30:00 (exact inclusive boundary) -> 'on_time', 0
        $shift->opened_at = \Carbon\Carbon::parse("{$date} 08:30:00", $tz);
        $this->assertSame('on_time', $shift->openingPunctuality());
        $this->assertSame(0, $shift->openingLateMinutes());

        // T7: 08:30:01 (first second past grace cutoff -> ceil(1/60) = 1m) -> 'late', 1
        $shift->opened_at = \Carbon\Carbon::parse("{$date} 08:30:01", $tz);
        $this->assertSame('late', $shift->openingPunctuality());
        $this->assertSame(1, $shift->openingLateMinutes());

        // T8: 08:45:00 (15 mins past grace cutoff) -> 'late', 15
        $shift->opened_at = \Carbon\Carbon::parse("{$date} 08:45:00", $tz);
        $this->assertSame('late', $shift->openingPunctuality());
        $this->assertSame(15, $shift->openingLateMinutes());

        // T9: null closed_at with active opened_at -> 'open', 0
        $shift->closed_at = null;
        $this->assertSame('open', $shift->closingPunctuality());
        $this->assertSame(0, $shift->closingLateMinutes());

        // T10: 16:29:59 -> 'early', 0
        $shift->closed_at = \Carbon\Carbon::parse("{$date} 16:29:59", $tz);
        $this->assertSame('early', $shift->closingPunctuality());
        $this->assertSame(0, $shift->closingLateMinutes());

        // T11: 16:59:59 -> 'early', 0
        $shift->closed_at = \Carbon\Carbon::parse("{$date} 16:59:59", $tz);
        $this->assertSame('early', $shift->closingPunctuality());
        $this->assertSame(0, $shift->closingLateMinutes());

        // T12: 17:00:00 (exact target close) -> 'on_time', 0
        $shift->closed_at = \Carbon\Carbon::parse("{$date} 17:00:00", $tz);
        $this->assertSame('on_time', $shift->closingPunctuality());
        $this->assertSame(0, $shift->closingLateMinutes());

        // T13: 17:15:00 -> 'on_time', 0
        $shift->closed_at = \Carbon\Carbon::parse("{$date} 17:15:00", $tz);
        $this->assertSame('on_time', $shift->closingPunctuality());
        $this->assertSame(0, $shift->closingLateMinutes());

        // T14: 17:30:00 (exact inclusive closing grace boundary) -> 'on_time', 0
        $shift->closed_at = \Carbon\Carbon::parse("{$date} 17:30:00", $tz);
        $this->assertSame('on_time', $shift->closingPunctuality());
        $this->assertSame(0, $shift->closingLateMinutes());

        // T15: 17:30:01 (first second past grace cutoff -> ceil(1/60) = 1m) -> 'delayed', 1
        $shift->closed_at = \Carbon\Carbon::parse("{$date} 17:30:01", $tz);
        $this->assertSame('delayed', $shift->closingPunctuality());
        $this->assertSame(1, $shift->closingLateMinutes());

        // T16: 18:15:00 (45 mins past grace cutoff) -> 'delayed', 45
        $shift->closed_at = \Carbon\Carbon::parse("{$date} 18:15:00", $tz);
        $this->assertSame('delayed', $shift->closingPunctuality());
        $this->assertSame(45, $shift->closingLateMinutes());

        // T17: opened_at valid, business_date cast to Carbon -> no exception
        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $shift->business_date);
        $this->assertSame($date, $shift->business_date->format('Y-m-d'));

        // T18: Policy overridden in SystemSetting to 09:00 start, open at 08:45 -> 'early'
        \App\Models\SystemSetting::put(\App\Models\SystemSetting::KEY_SHIFT_START_TIME, '09:00:00');
        $shift->opened_at = \Carbon\Carbon::parse("{$date} 08:45:00", $tz);
        $this->assertSame('early', $shift->openingPunctuality());

        // T19: Policy grace set to 0, open at 08:00:01 -> 'late', 1 min
        \App\Models\SystemSetting::put(\App\Models\SystemSetting::KEY_SHIFT_START_TIME, '08:00:00');
        \App\Models\SystemSetting::put(\App\Models\SystemSetting::KEY_SHIFT_GRACE_MINUTES, '0');
        $shift->opened_at = \Carbon\Carbon::parse("{$date} 08:00:01", $tz);
        $this->assertSame('late', $shift->openingPunctuality());
        $this->assertSame(1, $shift->openingLateMinutes());

        // Reset settings
        \App\Models\SystemSetting::put(\App\Models\SystemSetting::KEY_SHIFT_GRACE_MINUTES, '30');
    }

    public function test_late_open_requires_explanation_note_and_exposes_timing_payload(): void
    {
        $this->seed();
        $cashier = User::where('role', 'cashier')->firstOrFail();

        // Travel to late morning past 08:30 grace: 08:45 AM Manila time
        $tz = 'Asia/Manila';
        $lateTime = \Carbon\Carbon::parse(today()->toDateString().' 08:45:00', $tz);
        $this->travelTo($lateTime);

        // Attempting to open without note fails validation
        $this->actingAs($cashier)->postJson('/api/cash-shift/open', [
            'opening_float' => 200,
        ])->assertUnprocessable()->assertJsonValidationErrors('opening_note');

        // Short note (<5 chars) fails validation
        $this->postJson('/api/cash-shift/open', [
            'opening_float' => 200,
            'opening_note' => 'late',
        ])->assertUnprocessable()->assertJsonValidationErrors('opening_note');

        // Note >= 5 chars succeeds
        $res = $this->postJson('/api/cash-shift/open', [
            'opening_float' => 200,
            'opening_note' => 'POS system startup delay',
        ])->assertOk();

        $res->assertJsonPath('shift.opening_note', 'POS system startup delay')
            ->assertJsonPath('shift.timing.opening_status', 'late')
            ->assertJsonPath('shift.timing.opening_late_minutes', 15);

        // Current endpoint exposes timing object
        $this->getJson('/api/cash-shift')->assertOk()
            ->assertJsonPath('shift.timing.opening_status', 'late')
            ->assertJsonPath('shift.timing.schedule', '08:00 - 17:00');

        $this->travelBack();
    }
}
