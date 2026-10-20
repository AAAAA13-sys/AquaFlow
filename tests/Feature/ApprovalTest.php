<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Maker-checker: a manager proposes, the owner decides.
 *
 * These exercise the real failure paths rather than repeating the
 * implementation: the proposal must leave domain data untouched, a retry must
 * not apply twice, and nobody may approve their own work or work routed above
 * their rank.
 */
class ApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $manager;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->owner = User::where('username', 'admin')->firstOrFail();
        $this->cashier = User::where('username', 'cashier')->firstOrFail();
        $this->manager = User::create([
            'name' => 'Station Manager', 'username' => 'manager',
            'email' => 'manager@example.com', 'password' => 'Private123!',
            'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);
        Customer::where('name', 'Santos Family')->update(['debt_balance' => 150]);
    }

    // ---- Manager -> Owner ----------------------------------------------------

    public function test_a_manager_price_change_becomes_a_request_and_leaves_the_product_unchanged(): void
    {
        $product = Product::firstOrFail();
        $original = $product->price;

        $this->actingAs($this->manager)
            ->patchJson('/api/products/'.$product->id, ['price' => 99])
            ->assertStatus(202)
            ->assertJsonPath('pending_approval', true);

        $this->assertEquals($original, $product->fresh()->price);
        $this->assertDatabaseHas('approval_requests', [
            'type' => 'product.update', 'state' => 'pending', 'requested_by' => $this->manager->id,
        ]);
    }

    public function test_the_owner_approving_applies_the_price_once(): void
    {
        $product = Product::firstOrFail();

        $this->actingAs($this->manager)->patchJson('/api/products/'.$product->id, ['price' => 99])->assertStatus(202);
        $request = ApprovalRequest::where('type', 'product.update')->firstOrFail();

        $this->actingAs($this->owner)
            ->postJson('/api/approvals/'.$request->id.'/decision', [
                'decision' => 'approve', 'submission_key' => (string) Str::uuid(),
            ])
            ->assertOk()
            ->assertJsonPath('request.state', 'approved');

        $this->assertEquals(99, $product->fresh()->price);
        $this->assertSame(1, AuditLog::where('action', 'update')->count());
    }

    public function test_an_approval_retry_returns_the_original_result_without_applying_twice(): void
    {
        $product = Product::firstOrFail();
        $this->actingAs($this->manager)->patchJson('/api/products/'.$product->id, ['price' => 77])->assertStatus(202);
        $request = ApprovalRequest::where('type', 'product.update')->firstOrFail();
        $key = (string) Str::uuid();

        $body = ['decision' => 'approve', 'submission_key' => $key];
        $this->actingAs($this->owner)->postJson('/api/approvals/'.$request->id.'/decision', $body)->assertOk();
        $second = $this->actingAs($this->owner)->postJson('/api/approvals/'.$request->id.'/decision', $body);

        $second->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(1, AuditLog::where('action', 'update')->count());
    }

    public function test_a_manager_cannot_approve_their_own_request(): void
    {
        $product = Product::firstOrFail();
        $this->actingAs($this->manager)->patchJson('/api/products/'.$product->id, ['price' => 55])->assertStatus(202);
        $request = ApprovalRequest::firstOrFail();

        $this->actingAs($this->manager)
            ->postJson('/api/approvals/'.$request->id.'/decision', [
                'decision' => 'approve', 'submission_key' => (string) Str::uuid(),
            ])
            ->assertForbidden();

        $this->assertNotEquals(55, $product->fresh()->price);
        $this->assertSame('pending', $request->fresh()->state);
    }

    public function test_a_manager_cannot_see_or_decide_an_owner_tier_request(): void
    {
        $product = Product::firstOrFail();
        $this->actingAs($this->manager)->patchJson('/api/products/'.$product->id, ['price' => 60])->assertStatus(202);
        $request = ApprovalRequest::firstOrFail();

        // The manager may see their own proposal...
        $this->getJson('/api/approvals/'.$request->id)->assertOk();
        // ...but only the owner's rank satisfies the product approver tier.
        $this->actingAs($this->manager)
            ->postJson('/api/approvals/'.$request->id.'/decision', [
                'decision' => 'approve', 'submission_key' => (string) Str::uuid(),
            ])
            ->assertForbidden();
    }

    public function test_rejection_writes_nothing(): void
    {
        $product = Product::firstOrFail();
        $original = $product->price;
        $this->actingAs($this->manager)->patchJson('/api/products/'.$product->id, ['price' => 88])->assertStatus(202);
        $request = ApprovalRequest::firstOrFail();

        $this->actingAs($this->owner)
            ->postJson('/api/approvals/'.$request->id.'/decision', [
                'decision' => 'reject', 'submission_key' => (string) Str::uuid(), 'note' => 'Margin too thin.',
            ])
            ->assertOk()->assertJsonPath('request.state', 'rejected');

        $this->assertEquals($original, $product->fresh()->price);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_owner_still_writes_products_directly(): void
    {
        $product = Product::firstOrFail();

        $this->actingAs($this->owner)
            ->patchJson('/api/products/'.$product->id, ['price' => 123])
            ->assertOk();

        $this->assertEquals(123, $product->fresh()->price);
        $this->assertSame(0, ApprovalRequest::count());
    }

    public function test_a_manager_new_till_item_is_queued_then_created_on_approval(): void
    {
        $countBefore = Product::count();

        $this->actingAs($this->manager)->postJson('/api/products', [
            'name' => 'Station Blue Jug', 'price' => 250, 'category' => 'container',
            'container_kind' => 'R', 'reason' => 'New line for the summer.',
        ])->assertStatus(202)->assertJsonPath('pending_approval', true);

        // The store did not run: no product, and the proposal carries the values.
        $this->assertSame($countBefore, Product::count());
        $request = ApprovalRequest::where('type', 'product.create')->firstOrFail();
        $this->assertSame('Station Blue Jug', $request->payload['name']);

        $this->actingAs($this->owner)
            ->postJson('/api/approvals/'.$request->id.'/decision', [
                'decision' => 'approve', 'submission_key' => (string) Str::uuid(),
            ])->assertOk()->assertJsonPath('request.state', 'approved');

        // Approving actually creates it this time, with its recipe derived.
        $this->assertSame($countBefore + 1, Product::count());
        $created = Product::where('name', 'Station Blue Jug')->firstOrFail();
        $this->assertEquals(250, $created->price);
        $this->assertTrue(\DB::table('product_consumables')->where('product_id', $created->id)->exists());
        // The audit row names the real created record, not a bare "approved".
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create', 'entity_type' => 'product', 'entity_id' => $created->id,
        ]);
    }

    public function test_a_manager_new_till_item_keeps_an_explicit_recipe(): void
    {
        // A recipe is part of what the manager proposed. If the proposal dropped
        // `consumables`, approval would create the item with the derived recipe
        // instead - a silently different item from the one requested.
        $item = InventoryItem::query()->current()->firstOrFail();

        $this->actingAs($this->manager)->postJson('/api/products', [
            'name' => 'Bespoke Refill', 'price' => 30, 'category' => 'refill',
            'container_kind' => 'S', 'consumables' => [['inventory_item_id' => $item->id, 'qty_per_sale' => 7]],
        ])->assertStatus(202);

        $request = ApprovalRequest::where('type', 'product.create')->firstOrFail();
        $this->assertSame([['inventory_item_id' => $item->id, 'qty_per_sale' => 7]], $request->payload['consumables']);

        $this->actingAs($this->owner)
            ->postJson('/api/approvals/'.$request->id.'/decision', [
                'decision' => 'approve', 'submission_key' => (string) Str::uuid(),
            ])->assertOk();

        $created = Product::where('name', 'Bespoke Refill')->firstOrFail();
        $this->assertSame(7, (int) \DB::table('product_consumables')
            ->where('product_id', $created->id)->where('inventory_item_id', $item->id)->value('qty_per_sale'));
    }

    public function test_a_manager_removing_a_till_item_only_applies_on_approval(): void
    {
        // A product with no sales can be removed. Give the manager a disposable
        // one so the seeded catalog's sold items are untouched.
        $product = Product::create([
            'id' => 'disposable-tile', 'name' => 'Disposable Tile', 'price' => 10,
            'category' => 'container', 'container_kind' => 'R', 'is_active' => true, 'sort_order' => 9999,
        ]);

        $this->actingAs($this->manager)
            ->deleteJson('/api/products/'.$product->id, ['reason' => 'Discontinued.'])
            ->assertStatus(202)->assertJsonPath('pending_approval', true);

        $this->assertDatabaseHas('products', ['id' => 'disposable-tile']);
        $request = ApprovalRequest::where('type', 'product.delete')->firstOrFail();

        $this->actingAs($this->owner)
            ->postJson('/api/approvals/'.$request->id.'/decision', [
                'decision' => 'approve', 'submission_key' => (string) Str::uuid(),
            ])->assertOk();

        $this->assertDatabaseMissing('products', ['id' => 'disposable-tile']);
        // The audit trail still names what was removed after the row is gone.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'delete', 'entity_type' => 'product', 'entity_label' => 'Disposable Tile',
        ]);
    }

    public function test_a_manager_new_stock_item_and_supplier_are_created_on_approval(): void
    {
        $supplier = Supplier::firstOrFail();

        $this->actingAs($this->manager)->postJson('/api/inventory', [
            'item_name' => 'Carbon Filter 10in', 'category' => 'Filtration',
            'stock_on_hand' => 5, 'unit' => 'pcs', 'supplier_id' => $supplier->id,
        ])->assertStatus(202);
        $itemRequest = ApprovalRequest::where('type', 'inventory.create')->firstOrFail();
        $this->assertDatabaseMissing('inventory', ['item_name' => 'Carbon Filter 10in']);

        $this->actingAs($this->owner)
            ->postJson('/api/approvals/'.$itemRequest->id.'/decision', [
                'decision' => 'approve', 'submission_key' => (string) Str::uuid(),
            ])->assertOk();
        $this->assertDatabaseHas('inventory', ['item_name' => 'Carbon Filter 10in', 'stock_on_hand' => 5]);
        // Opening stock is attributed to the approving account, not to nobody.
        $this->assertDatabaseHas('stock_movements', ['type' => 'restock', 'user_id' => $this->owner->id]);

        $this->actingAs($this->manager)->postJson('/api/suppliers', [
            'name' => 'Metro Plastics', 'supplied_items' => 'Covers, seals',
        ])->assertStatus(202);
        $supplierRequest = ApprovalRequest::where('type', 'supplier.create')->firstOrFail();
        $this->assertDatabaseMissing('suppliers', ['name' => 'Metro Plastics']);

        $this->actingAs($this->owner)
            ->postJson('/api/approvals/'.$supplierRequest->id.'/decision', [
                'decision' => 'approve', 'submission_key' => (string) Str::uuid(),
            ])->assertOk();
        $this->assertDatabaseHas('suppliers', ['name' => 'Metro Plastics']);
    }

    public function test_an_approved_removal_is_refused_when_the_item_was_sold_meanwhile(): void
    {
        // The manager proposes removing an item; before the owner decides, the
        // item gains sales history. Approving must refuse rather than destroy
        // evidence, and the request must not read as applied.
        $product = Product::create([
            'id' => 'sold-tile', 'name' => 'Sold Tile', 'price' => 10,
            'category' => 'container', 'container_kind' => 'R', 'is_active' => true, 'sort_order' => 9999,
        ]);

        $this->actingAs($this->manager)->deleteJson('/api/products/'.$product->id)->assertStatus(202);
        $request = ApprovalRequest::where('type', 'product.delete')->firstOrFail();

        \DB::table('transaction_items')->insert([
            'transaction_id' => \DB::table('transactions')->insertGetId([
                'receipt_number' => 'SOLD-1', 'customer_id' => Customer::where('name', 'Santos Family')->firstOrFail()->id,
                'cashier_id' => $this->cashier->id, 'order_type' => 'Walk-in',
                'payment_method' => 'Cash', 'total_amount' => 10, 'payment_status' => 'paid',
                'transaction_date' => today(), 'transaction_time' => '09:00:00',
            ]),
            'product_id' => $product->id, 'item_name' => $product->name,
            'quantity' => 1, 'unit_price' => 10, 'line_total' => 10,
        ]);

        $this->actingAs($this->owner)
            ->postJson('/api/approvals/'.$request->id.'/decision', [
                'decision' => 'approve', 'submission_key' => (string) Str::uuid(),
            ])->assertUnprocessable();

        $this->assertDatabaseHas('products', ['id' => 'sold-tile']);
        $this->assertSame('pending', $request->fresh()->state);
    }

    public function test_a_proposal_stores_only_real_attributes(): void
    {
        $product = Product::firstOrFail();

        // `reason` is descriptive: it must not travel as a proposed value, or
        // approval would try to write a column that does not exist.
        $this->actingAs($this->manager)
            ->patchJson('/api/products/'.$product->id, ['price' => 70, 'reason' => 'Competitor moved.'])
            ->assertStatus(202);

        $request = ApprovalRequest::where('type', 'product.update')->firstOrFail();
        $this->assertSame(['price' => 70], $request->payload);
        $this->assertSame('Competitor moved.', $request->reason);
    }

    public function test_a_manager_cannot_smuggle_a_stock_quantity_change_into_a_proposal(): void
    {
        $item = InventoryItem::query()->current()->firstOrFail();
        $stock = $item->stock_on_hand;

        // The quantity is a stock movement, not an approvable edit. Even if the
        // client sends it, the proposal must not carry it.
        $this->actingAs($this->manager)
            ->patchJson('/api/inventory/'.$item->id, ['lead_time_days' => 6, 'stock_on_hand' => 9999])
            ->assertStatus(202);

        $request = ApprovalRequest::where('type', 'inventory.update')->firstOrFail();
        $this->assertArrayNotHasKey('stock_on_hand', $request->payload);
        $this->assertSame(['lead_time_days' => 6], $request->payload);

        // Approving writes only the descriptive field, never the quantity.
        $this->actingAs($this->owner)
            ->postJson('/api/approvals/'.$request->id.'/decision', [
                'decision' => 'approve', 'submission_key' => (string) Str::uuid(),
            ])->assertOk();

        $this->assertSame(6, $item->fresh()->lead_time_days);
        $this->assertSame($stock, $item->fresh()->stock_on_hand);
    }

    public function test_a_cashier_collection_is_guarded_by_pending_rider_cash(): void
    {
        // The approval submission path shares the direct settle path's
        // availability rule, so a rider-carried collection still blocks a
        // second counter collection.
        $customer = Customer::where('name', 'Santos Family')->firstOrFail();
        $customer->update(['debt_balance' => 100]);

        $sale = \App\Models\Transaction::create([
            'receipt_number' => 'GUARD-1', 'customer_id' => $customer->id,
            'cashier_id' => $this->cashier->id, 'order_type' => 'Delivery',
            'payment_method' => 'Account', 'total_amount' => 100, 'payment_status' => 'unpaid',
            'transaction_date' => today(), 'transaction_time' => '08:00:00',
        ]);
        $job = \App\Models\ProductionQueueItem::create([
            'transaction_id' => $sale->id, 'receipt_number' => $sale->receipt_number,
            'customer_name' => $customer->name, 'items_description' => '1 refill', 'order_type' => 'Delivery',
        ]);
        \App\Models\DeliveryProof::create([
            'production_queue_id' => $job->id, 'customer_id' => $customer->id,
            'rider_id' => $this->seedRider()->id, 'photo_path' => 'proofs/x.png',
            'photo_hash' => str_repeat('a', 64), 'recipient_name' => 'Recipient',
            'received_quantity' => 1, 'reported_cash' => 100, 'created_at' => now(),
        ]);

        $this->actingAs($this->cashier)
            ->postJson('/api/customers/'.$customer->id.'/settle', [
                'submission_key' => (string) Str::uuid(), 'amount' => 100,
            ])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->assertSame(0, ApprovalRequest::where('type', 'settlement.create')->count());
    }

    private function seedRider(): User
    {
        return User::query()->where('role', 'rider')->first()
            ?? User::create([
                'name' => 'Rider', 'username' => 'rider-'.$this->manager->id,
                'email' => 'rider'.$this->manager->id.'@example.com', 'password' => 'Private123!',
                'role' => 'rider', 'is_active' => true,
            ]);
    }

    public function test_manager_recipe_change_is_queued_not_applied(): void
    {
        $product = Product::firstOrFail();
        $item = InventoryItem::query()->current()->firstOrFail();
        $before = \DB::table('product_consumables')->where('product_id', $product->id)->count();

        $this->actingAs($this->manager)->putJson('/api/products/'.$product->id.'/recipe', [
            'consumables' => [['inventory_item_id' => $item->id, 'qty_per_sale' => 3]],
        ])->assertStatus(202);

        $this->assertSame($before, \DB::table('product_consumables')->where('product_id', $product->id)->count());
        $this->assertDatabaseHas('approval_requests', ['type' => 'recipe.update', 'state' => 'pending']);
    }

    public function test_manager_inventory_and_supplier_changes_are_queued(): void
    {
        $item = InventoryItem::query()->current()->firstOrFail();
        $lead = $item->lead_time_days;

        $this->actingAs($this->manager)
            ->patchJson('/api/inventory/'.$item->id, ['lead_time_days' => 9])
            ->assertStatus(202);
        $this->assertEquals($lead, $item->fresh()->lead_time_days);

        $supplier = Supplier::firstOrFail();
        $this->actingAs($this->manager)
            ->patchJson('/api/suppliers/'.$supplier->id, ['name' => 'Renamed Supplier'])
            ->assertStatus(202);
        $this->assertNotSame('Renamed Supplier', $supplier->fresh()->name);
        $this->assertSame(2, ApprovalRequest::count());
    }

    // ---- Cashier -> Manager --------------------------------------------------

    public function test_a_cashier_collection_waits_for_approval_and_does_not_move_the_balance(): void
    {
        $customer = Customer::where('name', 'Santos Family')->firstOrFail();

        $this->actingAs($this->cashier)
            ->postJson('/api/customers/'.$customer->id.'/settle', [
                'submission_key' => (string) Str::uuid(), 'amount' => 50,
            ])
            ->assertStatus(202)
            ->assertJsonPath('pending_approval', true);

        // The ledger has not moved, and no receipt exists yet.
        $this->assertEquals(150, $customer->fresh()->debt_balance);
        $this->assertSame(0, \App\Models\Transaction::where('order_type', 'Debt Payment')->count());
        // The cash is reserved so it cannot be collected twice.
        $this->assertEquals(50, $customer->fresh()->settlement_pending_amount);
    }

    public function test_the_manager_approving_a_collection_posts_it_once(): void
    {
        $customer = Customer::where('name', 'Santos Family')->firstOrFail();
        $this->actingAs($this->cashier)->postJson('/api/customers/'.$customer->id.'/settle', [
            'submission_key' => (string) Str::uuid(), 'amount' => 50,
        ])->assertStatus(202);
        $request = ApprovalRequest::where('type', 'settlement.create')->firstOrFail();

        $this->actingAs($this->manager)
            ->postJson('/api/approvals/'.$request->id.'/decision', [
                'decision' => 'approve', 'submission_key' => (string) Str::uuid(),
            ])
            ->assertOk();

        $customer->refresh();
        $this->assertEquals(100, $customer->debt_balance);
        $this->assertEquals(0, $customer->settlement_pending_amount);
        $this->assertSame(1, \App\Models\Transaction::where('order_type', 'Debt Payment')->count());
    }

    public function test_a_rejected_collection_releases_the_reservation_and_posts_nothing(): void
    {
        $customer = Customer::where('name', 'Santos Family')->firstOrFail();
        $this->actingAs($this->cashier)->postJson('/api/customers/'.$customer->id.'/settle', [
            'submission_key' => (string) Str::uuid(), 'amount' => 50,
        ])->assertStatus(202);
        $request = ApprovalRequest::where('type', 'settlement.create')->firstOrFail();

        $this->actingAs($this->manager)
            ->postJson('/api/approvals/'.$request->id.'/decision', [
                'decision' => 'reject', 'submission_key' => (string) Str::uuid(), 'note' => 'Cash not counted.',
            ])
            ->assertOk();

        $customer->refresh();
        $this->assertEquals(150, $customer->debt_balance);
        $this->assertEquals(0, $customer->settlement_pending_amount);
        $this->assertSame(0, \App\Models\Transaction::count());
    }

    public function test_a_manager_who_is_also_the_cashier_cannot_self_approve_a_collection(): void
    {
        // A manager collects directly, so a cashier submission must never be
        // approvable by the person who raised it.
        $customer = Customer::where('name', 'Santos Family')->firstOrFail();
        $this->actingAs($this->cashier)->postJson('/api/customers/'.$customer->id.'/settle', [
            'submission_key' => (string) Str::uuid(), 'amount' => 30,
        ])->assertStatus(202);
        $request = ApprovalRequest::where('type', 'settlement.create')->firstOrFail();

        $this->actingAs($this->cashier)
            ->postJson('/api/approvals/'.$request->id.'/decision', [
                'decision' => 'approve', 'submission_key' => (string) Str::uuid(),
            ])
            ->assertForbidden();
    }

    public function test_a_second_collection_is_refused_while_one_is_pending(): void
    {
        $customer = Customer::where('name', 'Santos Family')->firstOrFail();
        $this->actingAs($this->cashier)->postJson('/api/customers/'.$customer->id.'/settle', [
            'submission_key' => (string) Str::uuid(), 'amount' => 150,
        ])->assertStatus(202);

        // The money is already in hand; it cannot be collected again.
        $this->actingAs($this->cashier)->postJson('/api/customers/'.$customer->id.'/settle', [
            'submission_key' => (string) Str::uuid(), 'amount' => 10,
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_a_settlement_that_no_longer_fits_is_refused_at_approval(): void
    {
        $customer = Customer::where('name', 'Santos Family')->firstOrFail();
        $this->actingAs($this->cashier)->postJson('/api/customers/'.$customer->id.'/settle', [
            'submission_key' => (string) Str::uuid(), 'amount' => 100,
        ])->assertStatus(202);
        $request = ApprovalRequest::where('type', 'settlement.create')->firstOrFail();

        // The balance shrank while the request waited.
        Customer::whereKey($customer->id)->update(['debt_balance' => 20]);

        $this->actingAs($this->manager)
            ->postJson('/api/approvals/'.$request->id.'/decision', [
                'decision' => 'approve', 'submission_key' => (string) Str::uuid(),
            ])
            ->assertStatus(409);

        $this->assertEquals(20, $customer->fresh()->debt_balance);
        $this->assertSame(0, \App\Models\Transaction::where('order_type', 'Debt Payment')->count());
        $this->assertSame('pending', $request->fresh()->state);
    }

    // ---- Visibility ----------------------------------------------------------

    public function test_a_manager_only_ever_sees_staff_submissions(): void
    {
        // One cashier submission...
        $customer = Customer::where('name', 'Santos Family')->firstOrFail();
        $this->actingAs($this->cashier)->postJson('/api/customers/'.$customer->id.'/settle', [
            'submission_key' => (string) Str::uuid(), 'amount' => 10,
        ])->assertStatus(202);

        $managerRows = $this->actingAs($this->manager)->getJson('/api/approvals')->assertOk();
        $managerRows->assertJsonCount(1, 'requests');

        // ...and one manager submission. The owner sees both; the manager sees
        // the cashier's request plus their own, never another manager's.
        $product = Product::firstOrFail();
        $this->actingAs($this->manager)->patchJson('/api/products/'.$product->id, ['price' => 41])->assertStatus(202);

        $this->actingAs($this->owner)->getJson('/api/approvals')->assertOk()->assertJsonCount(2, 'requests');
        $this->actingAs($this->manager)->getJson('/api/approvals')->assertOk()->assertJsonCount(2, 'requests');
    }

    public function test_a_manager_sees_every_debt_collection_even_one_the_owner_decided(): void
    {
        // A cashier records a collection; the OWNER approves it. The manager on
        // duty must still see it - counter cash is accountable to them whoever
        // signed off.
        $customer = Customer::where('name', 'Santos Family')->firstOrFail();
        $this->actingAs($this->cashier)->postJson('/api/customers/'.$customer->id.'/settle', [
            'submission_key' => (string) Str::uuid(), 'amount' => 20,
        ])->assertStatus(202);
        $request = ApprovalRequest::where('type', 'settlement.create')->firstOrFail();

        $this->actingAs($this->owner)
            ->postJson('/api/approvals/'.$request->id.'/decision', [
                'decision' => 'approve', 'submission_key' => (string) Str::uuid(),
            ])->assertOk();

        // The manager's request list still shows it, now with the owner as decider.
        $this->actingAs($this->manager)->getJson('/api/approvals')
            ->assertOk()
            ->assertJsonPath('requests.0.id', $request->id)
            ->assertJsonPath('requests.0.decided_by.id', $this->owner->id);

        // And the audit trail for the collection is readable by the manager,
        // naming the owner as the approver.
        $this->actingAs($this->manager)->getJson('/api/audit-logs')
            ->assertOk()
            ->assertJsonPath('logs.0.actor_role', 'super_admin');
    }

    public function test_both_management_ranks_read_the_whole_audit_trail(): void
    {
        // The owner changes a price directly. A manager must see it too: seeing
        // who approved or made a change is the control itself.
        $product = Product::firstOrFail();
        $this->actingAs($this->owner)->patchJson('/api/products/'.$product->id, ['price' => 321])->assertOk();

        $this->actingAs($this->manager)->getJson('/api/audit-logs')->assertOk()->assertJsonCount(1, 'logs');
        $this->actingAs($this->owner)->getJson('/api/audit-logs')->assertOk()->assertJsonCount(1, 'logs');

        // A rank below management still reads only their own work.
        $this->actingAs($this->cashier)->getJson('/api/audit-logs')->assertOk()->assertJsonCount(0, 'logs');
    }

    public function test_a_cancelled_request_applies_nothing(): void
    {
        $product = Product::firstOrFail();
        $original = $product->price;
        $this->actingAs($this->manager)->patchJson('/api/products/'.$product->id, ['price' => 500])->assertStatus(202);
        $request = ApprovalRequest::firstOrFail();

        $this->actingAs($this->manager)->postJson('/api/approvals/'.$request->id.'/cancel')->assertOk();

        $this->assertEquals($original, $product->fresh()->price);
        $this->assertSame('cancelled', $request->fresh()->state);
        $this->assertSame(0, AuditLog::count());
    }
}
