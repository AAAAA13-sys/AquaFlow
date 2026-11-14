<?php

namespace Tests\Feature;

use App\Models\CashShift;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\ProductionQueueItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CustomerLedgerService;
use App\Services\ExpenseService;
use App\Services\StockMovementService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Reports & profit. Data is created through the real checkout, void,
 * collection, purchase-order and expense paths so the report is checked
 * against what those paths actually write.
 */
class ReportsFeatureTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $manager;
    private User $cashier;
    private CarbonImmutable $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::where('username', 'admin')->firstOrFail();
        $this->manager = User::where('username', 'manager')->firstOrFail();
        $this->cashier = User::where('username', 'cashier')->firstOrFail();
        // A fixed station-time business day keeps every boundary explicit.
        $this->day = CarbonImmutable::parse('2026-11-10 00:00:00', config('app.timezone'));
        $this->at('08:00');
    }

    private function at(string $time, int $dayOffset = 0): void
    {
        $this->travelTo($this->day->addDays($dayOffset)->setTimeFromTimeString($time));
    }

    private function summary(?User $as = null, ?string $from = null, ?string $to = null)
    {
        $date = $this->day->toDateString();

        return $this->actingAs($as ?? $this->owner)
            ->getJson('/api/reports/summary?from='.($from ?? $date).'&to='.($to ?? $date));
    }

    private function sell(array $items, array $override = []): array
    {
        // Tests here sell across several days. The drawer guard rightly refuses
        // a sale while an earlier day's drawer is open, so settle that day here.
        // Reports are computed from transactions, not from drawer records.
        CashShift::where('user_id', $this->cashier->id)->whereNull('closed_at')
            ->whereDate('business_date', '<', today())->update(['closed_at' => now()]);
        $this->openDrawer();

        return $this->actingAs($this->cashier)->postJson('/api/transactions', array_replace([
            'submission_key' => (string) Str::uuid(),
            'customer_id' => (int) Customer::where('name', 'Walk-in Guest')->value('id'),
            'order_type' => 'Walk-in',
            'cash_tendered' => 1000,
            'items' => $items,
        ], $override))->assertCreated()->json('transaction');
    }

    private function voidSale(int $transactionId): void
    {
        $approver = User::create(['username' => 'rep-approver', 'name' => 'Approver', 'role' => 'super_admin',
            'is_active' => true, 'password' => 'Approver123!']);
        $this->actingAs($this->cashier)->postJson('/api/voids', [
            'submission_key' => (string) Str::uuid(), 'transaction_id' => $transactionId,
            'reason' => 'Entered twice', 'approver_username' => $approver->username, 'approver_password' => 'Approver123!',
        ])->assertCreated();
    }

    private function jug(): InventoryItem
    {
        return InventoryItem::where('item_name', '5 Gallon Slim Jug')->firstOrFail();
    }

    /** Creates, orders and fully receives a purchase order through the API. */
    private function receivePo(array $lines): array
    {
        $supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $po = $this->actingAs($this->owner)->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier->id, 'expected_date' => $this->day->addDays(5)->toDateString(), 'lines' => $lines,
        ])->assertCreated()->json('purchase_order');
        $this->actingAs($this->owner)->postJson("/api/purchase-orders/{$po['id']}/order")->assertOk();
        $po = $this->actingAs($this->owner)->getJson("/api/purchase-orders/{$po['id']}")->json('purchase_order');
        $this->actingAs($this->owner)->postJson("/api/purchase-orders/{$po['id']}/receive", [
            'submission_key' => (string) Str::uuid(),
            'lines' => array_map(fn ($l) => ['line_id' => $l['id'], 'qty' => $l['qty_ordered']], $po['lines']),
        ])->assertOk();

        return $po;
    }

    public function test_sales_totals_match_transactions_and_exclude_debt_payments_and_voids(): void
    {
        // The day before the period: must not count.
        $this->at('15:00', -1);
        $this->sell([['product_id' => 'slim', 'quantity' => 4]]);

        $this->at('09:00');
        $this->sell([['product_id' => 'slim', 'quantity' => 2]]);                                   // 70.00
        $this->sell([['product_id' => 'round', 'quantity' => 1], ['product_id' => 'newS', 'quantity' => 1]]); // 285.00
        $voided = $this->sell([['product_id' => 'slim', 'quantity' => 1]]);                           // 35.00, voided
        $this->voidSale($voided['id']);

        // A delivery charged to the account: revenue, not a collection.
        $reyes = Customer::where('name', 'Reyes Store')->firstOrFail();
        $delivery = $this->sell([['product_id' => 'newR', 'quantity' => 1]], [
            'customer_id' => $reyes->id, 'order_type' => 'Delivery', 'cash_tendered' => 0,
        ]);                                                                                            // 250.00

        // The rider brings the cash back: an accepted remittance.
        $job = ProductionQueueItem::where('transaction_id', $delivery['id'])->firstOrFail();
        $rider = User::where('username', 'rider')->firstOrFail();
        $proofId = DB::table('delivery_proofs')->insertGetId([
            'production_queue_id' => $job->id, 'customer_id' => $reyes->id, 'rider_id' => $rider->id,
            'photo_path' => 'proofs/test.jpg', 'photo_hash' => str_repeat('a', 64), 'recipient_name' => 'Reyes',
            'received_quantity' => 1, 'reported_cash' => 250, 'created_at' => now(),
        ]);
        app(CustomerLedgerService::class)->settle($reyes->fresh(), 250, $this->cashier, (string) Str::uuid(), $proofId);

        // An ordinary counter collection on an old balance: a debt payment, not revenue.
        $santos = Customer::where('name', 'Santos Family')->firstOrFail();
        $santos->update(['debt_balance' => 150]);
        $this->actingAs($this->manager)->postJson("/api/customers/{$santos->id}/settle", [
            'submission_key' => (string) Str::uuid(), 'amount' => 100,
        ])->assertOk();

        $this->at('18:00');
        $report = $this->summary()->assertOk();

        // Cross-check against Sales History for the same day, using the
        // page's own rule: not voided and not a Debt Payment.
        $history = collect($this->actingAs($this->owner)->getJson('/api/transactions?'.http_build_query([
            'from' => $this->day->toDateString().'T00:00', 'to' => $this->day->toDateString().'T23:59', 'paginated' => 1,
        ]))->assertOk()->json('transactions'));
        $sales = $history->filter(fn ($t) => ! $t['voided'] && $t['type'] !== 'Debt Payment');
        $this->assertSame(number_format($sales->sum('total'), 2, '.', ''), $report->json('sales.gross'));
        $this->assertSame($sales->count(), $report->json('sales.count'));

        $report->assertJsonPath('period.from', '2026-11-10')->assertJsonPath('period.to', '2026-11-10')
            ->assertJsonPath('sales.gross', '605.00')
            ->assertJsonPath('sales.count', 3)
            ->assertJsonPath('sales.discounts', '0.00');
        $vat = Transaction::whereNull('voided_at')->where('order_type', '!=', 'Debt Payment')
            ->whereDate('transaction_date', '2026-11-10')->sum('vat_amount');
        $this->assertSame(number_format((float) $vat, 2, '.', ''), $report->json('sales.vat'));
        $this->assertSame(number_format(605 - (float) $vat, 2, '.', ''), $report->json('sales.net'));

        $types = collect($report->json('sales.by_order_type'))->keyBy('order_type');
        $this->assertSame(['Delivery', 'Walk-in'], $types->keys()->sort()->values()->all());
        $this->assertSame('355.00', $types['Walk-in']['gross']);
        $this->assertSame(2, $types['Walk-in']['count']);
        $this->assertSame('250.00', $types['Delivery']['gross']);

        $pay = collect($report->json('sales.by_payment_method'))->keyBy('payment_method');
        $this->assertSame('355.00', $pay['Cash']['gross']);
        $this->assertSame('250.00', $pay['Account']['gross']);

        $cashiers = $report->json('sales.by_cashier');
        $this->assertCount(1, $cashiers);
        $this->assertSame($this->cashier->name, $cashiers[0]['name']);
        $this->assertSame('605.00', $cashiers[0]['gross']);

        $products = collect($report->json('sales.by_product'))->keyBy('product_id');
        $this->assertSame(2, $products['slim']['qty']);
        $this->assertSame('70.00', $products['slim']['revenue']);
        $this->assertSame('35.00', $products['round']['revenue']);
        $this->assertSame('250.00', $products['newS']['revenue']);
        $this->assertSame('250.00', $products['newR']['revenue']);
        $this->assertSame('Slim 5-Gal Refill', $products['slim']['name']);

        // Collections: cash sales + plain debt payments + remittances, each counted once.
        $report->assertJsonPath('collections.sales_cash', '355.00')
            ->assertJsonPath('collections.debt_payments', '100.00')
            ->assertJsonPath('collections.remittances', '250.00')
            ->assertJsonPath('collections.total', '705.00');
    }

    public function test_cost_of_supplies_uses_latest_restock_cost_and_counts_unknown(): void
    {
        $jug = $this->jug();
        $stock = app(StockMovementService::class);

        $this->at('07:00');
        $stock->record($jug, ['type' => 'restock', 'qty' => 5, 'unit_cost' => 100], $this->owner);
        $this->at('08:00');
        $this->receivePo([['inventory_item_id' => $jug->id, 'qty' => 4, 'unit_cost' => 120]]);

        $this->at('10:00');
        $sale = $this->sell([['product_id' => 'newS', 'quantity' => 2], ['product_id' => 'slim', 'quantity' => 3]]);
        $this->at('10:30');
        // A refill makes it a production job, which is what a void reverses.
        $voided = $this->sell([['product_id' => 'newS', 'quantity' => 1], ['product_id' => 'slim', 'quantity' => 1]]);
        $this->voidSale($voided['id']);

        // A later, dearer restock must not reprice the earlier sale.
        $this->at('11:00');
        $stock->record($jug, ['type' => 'restock', 'qty' => 5, 'unit_cost' => 150], $this->owner);

        $this->at('18:00');
        $report = $this->summary()->assertOk();

        $movements = StockMovement::where('transaction_id', $sale['id'])->where('type', 'sale')->get();
        $jugUnits = -(int) $movements->where('inventory_item_id', $jug->id)->sum('qty');
        $this->assertSame(2, $jugUnits);
        $unknown = $movements->where('inventory_item_id', '!=', $jug->id);
        $this->assertGreaterThan(0, $unknown->count(), 'A refill consumes caps or seals that have no cost yet.');

        $report->assertJsonPath('cost_of_supplies.total', '240.00')
            ->assertJsonPath('cost_of_supplies.items_without_cost', $unknown->pluck('inventory_item_id')->unique()->count())
            ->assertJsonPath('cost_of_supplies.units_without_cost', -(int) $unknown->sum('qty'));
        $names = collect($report->json('cost_of_supplies.uncosted_items'))->pluck('name');
        $this->assertNotContains('5 Gallon Slim Jug', $names->all());

        // Purchases count only PO receipts, not manual restocks.
        $report->assertJsonPath('purchases_received.total', '480.00');
    }

    /** Inserts a sale row the way imported/generated history does: no stock movements. */
    private function legacySale(string $productId, array $override = []): int
    {
        $id = DB::table('transactions')->insertGetId(array_replace([
            'receipt_number' => 'OR-L'.Str::random(6), 'customer_id' => (int) Customer::where('name', 'Walk-in Guest')->value('id'),
            'cashier_id' => $this->cashier->id, 'order_type' => 'Walk-in', 'gallons_volume' => '1S',
            'subtotal_amount' => 31.25, 'vat_amount' => 3.75, 'auto_discount' => 0, 'manual_discount' => 0, 'total_amount' => 35,
            'payment_method' => 'Cash', 'cash_tendered' => 35, 'cash_change' => 0, 'payment_status' => 'paid', 'balance_after' => 0,
            'transaction_date' => '2026-11-10', 'transaction_time' => '09:30:00', 'created_at' => now(), 'updated_at' => now(),
        ], $override));
        DB::table('transaction_items')->insert(['transaction_id' => $id, 'product_id' => $productId, 'item_name' => $productId,
            'quantity' => 1, 'unit_price' => 35, 'line_total' => 35]);

        return $id;
    }

    public function test_sales_that_recorded_no_supply_use_are_flagged(): void
    {
        $this->at('09:00');
        $this->sell([['product_id' => 'slim', 'quantity' => 1]]);          // real checkout: writes sale movements
        $this->legacySale('slim');                                          // has a recipe, no movements: flagged
        $this->legacySale('round');                                         // flagged
        $this->legacySale('slim', ['voided_at' => now()]);                  // voided: not revenue
        $this->legacySale('slim', ['order_type' => 'Debt Payment']);        // collection: not a sale
        $this->legacySale('slim', ['transaction_date' => '2026-11-09']);    // outside the period
        $this->assertFalse(DB::table('product_consumables')->where('product_id', 'soap')->exists());
        $this->legacySale('soap');                                          // no recipe: consumes nothing, not flagged

        $this->at('18:00');
        $this->summary()->assertOk()
            ->assertJsonPath('cost_of_supplies.sales_without_consumption', 2)
            ->assertJsonPath('sales.count', 4);

        // A period with only POS sales reports none.
        $this->summary(null, '2026-11-11', '2026-11-11')->assertOk()
            ->assertJsonPath('cost_of_supplies.sales_without_consumption', 0);
    }

    public function test_expenses_and_purchases_feed_margin_and_cash_flow_formulas(): void
    {
        $this->at('08:00');
        $supplier = Supplier::query()->orderBy('id')->firstOrFail();
        $cap = InventoryItem::create(['item_name' => 'Report Test Cap', 'category' => 'Consumable', 'stock_on_hand' => 0,
            'unit' => 'pcs', 'safety_stock' => 0, 'reorder_point' => 0, 'target_stock' => 100, 'supplier_id' => $supplier->id]);
        $seal = InventoryItem::create(['item_name' => 'Report Test Seal', 'category' => 'Consumable', 'stock_on_hand' => 0,
            'unit' => 'pcs', 'safety_stock' => 0, 'reorder_point' => 0, 'target_stock' => 100, 'supplier_id' => $supplier->id]);
        $this->receivePo([
            ['inventory_item_id' => $cap->id, 'qty' => 40, 'unit_cost' => 12.5],
            ['inventory_item_id' => $seal->id, 'qty' => 10, 'unit_cost' => 3],
        ]);                                                                                          // 530.00

        $expenses = app(ExpenseService::class);
        $expenses->record($this->manager, ['submission_key' => (string) Str::uuid(), 'spent_on' => '2026-11-10',
            'category' => 'utilities', 'amount' => 1000]);
        $expenses->record($this->manager, ['submission_key' => (string) Str::uuid(), 'spent_on' => '2026-11-10',
            'category' => 'rent', 'amount' => 234.56]);
        $mistake = $expenses->record($this->manager, ['submission_key' => (string) Str::uuid(), 'spent_on' => '2026-11-10',
            'category' => 'other', 'amount' => 500]);
        $expenses->void($this->owner, $mistake, 'Entered twice');
        // Outside the period.
        $expenses->record($this->manager, ['submission_key' => (string) Str::uuid(), 'spent_on' => '2026-11-09',
            'category' => 'rent', 'amount' => 999]);

        $this->sell([['product_id' => 'slim', 'quantity' => 2]]);                                   // 70.00 cash

        $this->at('18:00');
        $report = $this->summary()->assertOk();

        $report->assertJsonPath('expenses.total', '1234.56')
            ->assertJsonPath('expenses.by_category.utilities', '1000.00')
            ->assertJsonPath('expenses.by_category.rent', '234.56')
            ->assertJsonPath('expenses.by_category.other', '0.00')
            ->assertJsonPath('purchases_received.total', '530.00');

        $net = (int) round((float) $report->json('sales.net') * 100);
        $cost = (int) round((float) $report->json('cost_of_supplies.total') * 100);
        $this->assertSame(number_format(($net - $cost - 123456) / 100, 2, '.', ''), $report->json('operating_margin.value'));
        $report->assertJsonPath('cash_flow.value', number_format((7000 - 53000 - 123456) / 100, 2, '.', ''));

        $this->assertStringContainsString('net sales', strtolower($report->json('operating_margin.formula')));
        $this->assertStringContainsString('cost of supplies', strtolower($report->json('operating_margin.formula')));
        $this->assertStringContainsString('expenses', strtolower($report->json('operating_margin.formula')));
        $this->assertStringContainsString('collected', strtolower($report->json('cash_flow.formula')));
        $this->assertStringContainsString('purchases', strtolower($report->json('cash_flow.formula')));
    }

    public function test_range_validation_and_management_only(): void
    {
        $this->summary(null, '2026-11-10', '2026-11-01')->assertStatus(422)->assertJsonValidationErrors('from');
        $this->summary(null, '2025-11-01', '2026-11-02')->assertStatus(422)->assertJsonValidationErrors('from');
        $this->summary(null, '2025-11-10', '2026-11-10')->assertOk()->assertJsonPath('period.days', 366);
        $this->summary(null, '10/11/2026', '2026-11-10')->assertStatus(422)->assertJsonValidationErrors('from');
        $this->summary(null, '2026-11-01', 'tomorrow')->assertStatus(422)->assertJsonValidationErrors('to');

        // Defaults to this month so far.
        $this->actingAs($this->owner)->getJson('/api/reports/summary')->assertOk()
            ->assertJsonPath('period.from', '2026-11-01')->assertJsonPath('period.to', '2026-11-10');

        $this->summary($this->manager)->assertOk()->assertJsonPath('sales.gross', '0.00')
            ->assertJsonPath('cost_of_supplies.items_without_cost', 0);
        $this->summary($this->cashier)->assertForbidden();
        $this->summary(User::where('username', 'rider')->firstOrFail())->assertForbidden();
        $this->summary(User::where('username', 'refiller')->firstOrFail())->assertForbidden();
    }

    public function test_reports_page_renders(): void
    {
        $this->actingAs($this->owner)->get('/admin/reports')->assertOk()
            ->assertSee('id="repPresets"', false)
            ->assertSee('id="repStats"', false)
            ->assertSee('js/admin/reports.js', false)
            ->assertSee('css/erp.css', false);
        $this->actingAs($this->manager)->get('/admin/reports')->assertOk();
        $this->actingAs($this->cashier)->get('/admin/reports')->assertRedirect();
    }
}
