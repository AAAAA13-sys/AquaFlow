<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\DemandForecast;
use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StoreInstallationService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AnalyticsAndGovernanceFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $unseeded = [
            'test_clean_install_has_no_demo_data_and_cannot_reset_an_existing_store',
            'test_invalid_credentials_do_not_create_partial_catalog',
            'test_customer_requires_a_deliverable_mobile_number',
            'test_profile_name_boundary_does_not_partially_write',
            'test_new_cashier_must_replace_default_password_before_work',
            'test_staff_name_limits_and_admin_password_validation',
            'test_unsupported_roles_cannot_be_created_or_signed_in',
            'test_all_application_source_files_are_utf8_without_bom',
            'test_palette_and_admin_scripts_use_correct_contract',
            'test_legacy_blade_references_are_retired',
            'test_trust_proxies_preserves_docker_ssl_scheme',
        ];

        if (!in_array($this->name(), $unseeded, true)) {
            $this->seed();
            config(['analytics.demo' => false]);
            $this->cashier = User::where('username', 'cashier')->first();
            $this->owner = User::where('username', 'admin')->first();
            $this->manager = User::where('role', User::ROLE_ADMIN)->where('username', '<>', 'admin')->first()
                ?? User::create([
                    'name' => 'Station Manager',
                    'username' => 'manager',
                    'email' => 'manager@example.com',
                    'password' => 'Private123!',
                    'role' => User::ROLE_ADMIN,
                    'is_active' => true,
                ]);
            Customer::where('name', 'Santos Family')->update(['debt_balance' => 150]);
        }
    }

    // --- From ForecastTest.php ---
    private User $cashier;

    private User $owner;
    private User $manager;

    

    private function storeForecast(string $series = 'Refill Gallons'): DemandForecast
    {
        return DemandForecast::query()->create([
            'series_name' => $series,
            'horizon_type' => 'Daily',
            'forecast_date' => today(),
            'historical_data' => array_fill(0, 30, 250),
            'forecasted_data' => [204.4, 219.1, 210.2, 212.3, 425.0, 416.8, 221.4],
            'model_order' => 'ARIMA(0,0,1)',
            'method' => 'arima_seasonal',
            'differencing' => 0,
            'aic_score' => 1033.44,
            'mape_score' => 10.15,
            'mae_score' => 28.56,
            'rmse_score' => 33.95,
            'adf_statistic' => -2.51,
            'adf_pvalue' => 0.0184,
            'ljung_box_pvalue' => 0.9796,
            'residual_std' => 40.12,
        ]);
    }

    public function test_u_s39_u_s41_forecasts_are_listed_per_series_with_diagnostics(): void
    {
        $this->storeForecast();

        $response = $this->actingAs($this->cashier)->getJson('/api/forecast');

        $response->assertOk()->assertJsonStructure([
            'forecasts' => ['Refill Gallons' => ['series', 'history', 'forecast', 'model' => ['order', 'method', 'mape', 'ljung_box_pvalue']]],
        ]);

        $this->assertCount(7, $response->json('forecasts.Refill Gallons.forecast'));
        $this->assertSame(10.15, $response->json('forecasts.Refill Gallons.model.mape'));
    }

    public function test_a_single_series_can_be_requested(): void
    {
        $this->storeForecast('Heat Shrink Seals');

        $this->actingAs($this->cashier)
            ->getJson('/api/forecast?series=Heat%20Shrink%20Seals')
            ->assertOk()
            ->assertJsonPath('forecast.series', 'Heat Shrink Seals');
    }

    public function test_an_unknown_series_returns_404(): void
    {
        $this->actingAs($this->cashier)
            ->getJson('/api/forecast?series=Nope')
            ->assertStatus(404);
    }

    public function test_only_the_owner_can_store_a_forecast(): void
    {
        $payload = [
            'series_name' => 'Refill Gallons',
            'historical' => [250, 260, 255],
            'forecasted' => [245, 250, 255],
            'model_order' => 'ARIMA(1,0,1)',
            'mape' => 9.5,
        ];

        $this->actingAs($this->cashier)->postJson('/api/forecast', $payload)->assertStatus(403);

        $this->actingAs($this->owner)
            ->postJson('/api/forecast', $payload)
            ->assertCreated()
            ->assertJsonPath('forecast.model.order', 'ARIMA(1,0,1)');
    }

    public function test_only_the_owner_can_trigger_a_forecast_run(): void
    {
        $this->actingAs($this->cashier)->postJson('/api/forecast/run')->assertStatus(403);
    }

    public function test_a_forecast_run_reports_a_clear_error_when_the_service_is_offline(): void
    {
        // Point the client at a dead port so the failure path is exercised.
        config(['analytics.url' => 'http://127.0.0.1:1']);
        config(['analytics.connect_timeout' => 1]);

        $response = $this->actingAs($this->owner)->postJson('/api/forecast/run');

        $response->assertStatus(502);
        $this->assertStringContainsString('analytics', strtolower((string) $response->json('message')));
    }

    public function test_storing_a_forecast_requires_series_values(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/api/forecast', ['series_name' => 'Refill Gallons', 'historical' => [], 'forecasted' => []])
            ->assertStatus(422);
    }
    public function test_presentation_refresh_uses_demo_history_without_running_sales_analytics(): void
    {
        config(['analytics.demo' => true]);
        \Illuminate\Support\Facades\Http::fake([
            '*/forecast' => \Illuminate\Support\Facades\Http::response([
                'forecast' => [25, 26, 32, 31, 24, 25, 24],
                'order' => 'ARIMA(0,0,1)',
                'mape' => 8.0,
            ]),
        ]);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $sales = \App\Models\Transaction::count();

        $this->actingAs($this->owner)->postJson('/api/forecast/run')
            ->assertOk()
            ->assertJsonPath('forecasts.Refill Gallons.model.diagnostics.data_source', 'synthetic_demo');

        $original = DemandForecast::latestFor('Refill Gallons');
        $this->assertCount(30, $original->historical_data);

        $this->travel(1)->days();
        $this->actingAs($this->owner)->postJson('/api/forecast/run')
            ->assertOk()
            ->assertJsonCount(30, 'forecasts.Refill Gallons.history');
        $this->artisan('aquaflow:forecast')->assertSuccessful();
        $latest = DemandForecast::latestFor('Refill Gallons');
        $this->assertSame($original->historical_data, $latest->historical_data);
        $this->assertCount(7, $latest->forecasted_data);
        $this->assertTrue($original->fresh()->exists);
        $this->travelBack();
        $this->assertSame($sales, \App\Models\Transaction::count());
        $this->assertSame('synthetic_demo', DemandForecast::latestFor('Refill Gallons')->model_diagnostics['data_source']);
    }

    public function test_demo_seeder_preserves_synthetic_source_and_measured_metrics(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response([
                'forecast' => [25, 26, 32, 31, 24, 25, 24],
                'order' => 'ARIMA(0,0,1)',
                'method' => 'arima_seasonal',
                'mape' => 8.0,
                'model_diagnostics' => ['validation_available' => true, 'validation_days' => 7],
            ]),
        ]);
        $sales = \App\Models\Transaction::count();
        $this->seed(\Database\Seeders\ForecastDemoSeeder::class);
        $this->assertSame($sales, \App\Models\Transaction::count());
        $forecast = DemandForecast::latestFor('Refill Gallons');
        $this->assertSame(8.0, $forecast->mape_score);
        $this->assertCount(30, $forecast->historical_data);
        $this->assertSame('synthetic_demo', $forecast->model_diagnostics['data_source']);
        $this->assertSame(7, $forecast->model_diagnostics['validation_days']);
    }

    // --- From ApprovalTest.php ---

    

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

        // `payload` is a MySQL JSON column, and its binary form normalizes
        // object key order on the way in. The proposal must carry the right
        // fields and values, not the accidental order the request sent, so
        // compare the recipe with both sides sorted by key.
        $expected = [['inventory_item_id' => $item->id, 'qty_per_sale' => 7]];
        $stored = $request->payload['consumables'];
        foreach ($expected as $index => $line) {
            ksort($expected[$index]);
        }
        foreach ($stored as $index => $line) {
            ksort($stored[$index]);
        }
        $this->assertSame($expected, $stored);

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

    // --- From AuditLogTest.php ---

    

    /** An approved collection is recorded against the approver, not the cashier. */
    private function approveOneCollection(): void
    {
        $customer = Customer::where('name', 'Santos Family')->firstOrFail();
        $this->actingAs($this->cashier)->postJson('/api/customers/'.$customer->id.'/settle', [
            'submission_key' => (string) Str::uuid(), 'amount' => 25,
        ])->assertStatus(202);

        $request = \App\Models\ApprovalRequest::where('type', 'settlement.create')->firstOrFail();
        $this->actingAs($this->manager)->postJson('/api/approvals/'.$request->id.'/decision', [
            'decision' => 'approve', 'submission_key' => (string) Str::uuid(),
        ])->assertOk();
    }

    public function test_an_approved_change_is_recorded_with_its_actor_and_context(): void
    {
        $this->approveOneCollection();

        $log = AuditLog::firstOrFail();
        $this->assertSame($this->manager->id, $log->actor_id);
        $this->assertSame(User::ROLE_ADMIN, $log->actor_role);
        $this->assertSame(AuditLog::CONTEXT_APPROVAL, $log->context);
        $this->assertNotNull($log->approval_request_id);
    }

    public function test_the_owner_sees_every_change(): void
    {
        $this->approveOneCollection();

        $this->actingAs($this->owner)->getJson('/api/audit-logs')->assertOk()
            ->assertJsonCount(AuditLog::count(), 'logs');
    }

    public function test_a_manager_reads_the_owners_change_too(): void
    {
        $product = Product::firstOrFail();
        $this->actingAs($this->owner)->patchJson('/api/products/'.$product->id, ['price' => 321])->assertOk();
        $this->assertSame(1, AuditLog::where('actor_id', $this->owner->id)->count());

        // Both management ranks read the whole trail, so the owner's change is
        // visible to the manager as well.
        $this->actingAs($this->manager)->getJson('/api/audit-logs')->assertOk()
            ->assertJsonCount(1, 'logs')
            ->assertJsonPath('logs.0.actor_role', 'super_admin');
    }

    public function test_a_cashier_reads_only_their_own_changes(): void
    {
        $this->approveOneCollection();

        // The recorded actor is the approver, so the cashier sees nothing.
        $this->actingAs($this->cashier)->getJson('/api/audit-logs')->assertOk()
            ->assertJsonCount(0, 'logs');
    }

    public function test_a_staff_supplied_filter_cannot_widen_their_own_scope(): void
    {
        $product = Product::firstOrFail();
        $this->actingAs($this->owner)->patchJson('/api/products/'.$product->id, ['price' => 654])->assertOk();

        // A cashier asking for the owner's actions explicitly still returns
        // nothing, because the scope is applied before the filter.
        $this->actingAs($this->cashier)
            ->getJson('/api/audit-logs?actor_id='.$this->owner->id)
            ->assertOk()->assertJsonCount(0, 'logs');

        // A manager sees the row, with or without the filter.
        $this->actingAs($this->manager)
            ->getJson('/api/audit-logs?actor_id='.$this->owner->id)
            ->assertOk()->assertJsonCount(1, 'logs');
    }

    public function test_a_deactivated_actor_still_reads_by_their_snapshotted_name(): void
    {
        $product = Product::firstOrFail();
        $this->actingAs($this->owner)->patchJson('/api/products/'.$product->id, ['price' => 999])->assertOk();

        $this->owner->update(['name' => 'Renamed Owner']);

        $log = $this->actingAs($this->owner)->getJson('/api/audit-logs')->json('logs.0');
        // The snapshot survives the rename.
        $this->assertSame('Admin (kmikaelim11)', $log['actor']);
    }

    public function test_only_changed_fields_are_stored(): void
    {
        $product = Product::firstOrFail();
        $this->actingAs($this->owner)->patchJson('/api/products/'.$product->id, ['price' => 44])->assertOk();

        $changes = AuditLog::firstOrFail()->changes;
        $this->assertArrayHasKey('price', $changes);
        // The trail does not duplicate unrelated columns.
        $this->assertArrayNotHasKey('category', $changes);
    }

    // --- From OctoberUpdatesTest.php ---
    public function test_transaction_range_includes_selected_minutes_and_rejects_reversed_dates(): void
    {
        $this->seed();
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->actingAs($admin);
        $customer = \App\Models\Customer::firstOrCreate(['name' => 'Test Cust'], ['customer_type' => 'regular']);
        $sale = Transaction::first() ?? Transaction::create(['receipt_number' => 'OR-OCT-001', 'total_amount' => 50, 'cashier_id' => $admin->id, 'customer_id' => $customer->id, 'order_type' => 'Walk-in', 'payment_method' => 'Cash', 'transaction_date' => '2026-02-03', 'transaction_time' => '10:15:59']);
        $sale->forceFill(['transaction_date' => '2026-02-03', 'transaction_time' => '10:15:59'])->save();
        $url = '/api/transactions?from=2026-02-03T10:15&to=2026-02-03T10:15';
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'transactions')->assertJsonPath('transactions.0.id', $sale->id);
        $this->getJson('/api/transactions?from=2026-02-03T10:16&to=2026-02-03T11:00')->assertOk()->assertJsonCount(0, 'transactions');
        $this->getJson('/api/transactions?from=2026-02-04T00:00&to=2026-02-03T23:59')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/api/transactions?from=invalid')->assertUnprocessable()->assertJsonValidationErrors('from');
    }

    // --- From StoreInstallationTest.php ---
    private function details(): array
    {
        return ['name' => 'Store Owner', 'username' => 'owner', 'password' => 'Store123', 'email' => 'owner@example.com'];
    }

    public function test_clean_install_has_no_demo_data_and_cannot_reset_an_existing_store(): void
    {
        app(StoreInstallationService::class)->install($this->details());
        $owner = User::sole();
        $this->assertTrue($owner->isAdmin());
        $this->assertTrue(Hash::check('Store123', $owner->password));
        $this->assertSame('owner@example.com', $owner->email);
        foreach (['transactions', 'employees', 'suppliers', 'stock_movements'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseHas('customers', ['system_key' => 'walk-in']);
        $this->assertDatabaseCount('customers', 1);
        $this->assertGreaterThan(0, InventoryItem::count());
        $this->assertEquals(0, InventoryItem::sum('stock_on_hand'));
        $this->assertDatabaseCount('products', 7);
        $item = InventoryItem::first();
        $item->update(['stock_on_hand' => 12]);
        $this->assertFalse(app(StoreInstallationService::class)->install($this->details()));
        $this->assertSame(12, $item->fresh()->stock_on_hand);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_invalid_credentials_do_not_create_partial_catalog(): void
    {
        try {
            app(StoreInstallationService::class)->install(array_replace($this->details(), ['password' => '1234']));
            $this->fail('Weak password accepted');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('password', $exception->errors());
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('products', 0);
    }

    // --- From PageTest.php ---

    

    private function js(string $relative): string
    {
        return (string) file_get_contents(base_path('public/'.$relative));
    }

    public function test_guests_are_sent_to_the_right_login_screen(): void
    {
        $this->get('/cashier')->assertRedirect('/');
        $this->get('/cashier/customer-custody')->assertRedirect('/');
        $this->get('/cashier/queue')->assertRedirect('/');
        $this->get('/cashier/history')->assertRedirect('/');
        $this->get('/admin')->assertRedirect('/');
        $this->get('/admin/dashboard')->assertRedirect('/');
    }

    // ---------- The terminal is a three-stage POS ----------

    public function test_stage_one_is_customer_and_address_only(): void
    {
        $response = $this->actingAs($this->cashier)->get('/cashier');

        $response->assertOk()
            ->assertSee('Customer', false)
            ->assertSee('+ New Customer', false)
            ->assertSee('Settle Debt', false)
            ->assertSee('id="custSearch"', false)
            ->assertSee('id="custCard"', false)
            ->assertSee('id="custList"', false)
            // The spec removed phone capture from registration.
            ->assertDontSee('Contact Number', false)
            ->assertDontSee('regPhone', false);
    }

    public function test_order_type_moves_to_stage_two(): void
    {
        $response = $this->actingAs($this->cashier)->get('/cashier');

        $response->assertOk()
            ->assertSee('Order Type', false)
            ->assertSee("confirmOrderType('Walk-in')", false)
            ->assertSee("confirmOrderType('Delivery')", false);

        // Stage 1 must not offer an order type: it belongs to stage 2.
        $stageOne = explode('data-step="2"', $response->getContent())[0];
        $this->assertStringNotContainsString('confirmOrderType(', $stageOne);
    }

    public function test_stage_two_lists_products_and_the_items_bought(): void
    {
        $response = $this->actingAs($this->cashier)->get('/cashier');

        // Tapping a tile must list the item on the same stage.
        $response->assertOk()
            ->assertSee('id="productGrid"', false)
            ->assertSee('Order items', false)
            ->assertSee('id="boughtItems"', false)
            ->assertSee('id="boughtTotal"', false)
            ->assertSee('Order total', false)
            // Walk-in / Delivery is a segmented toggle at the top of stage 2.
            ->assertSee('class="stage-segment"', false)
            ->assertSee('Walk-In', false)
            ->assertSee('Delivery', false)
            // Each segment is labelled by a sprite icon rather than an emoji.
            ->assertSee('#i-walkin', false)
            ->assertSee('#i-truck', false);
    }

    public function test_the_cashier_never_enters_filled_out_or_returned_containers(): void
    {
        $response = $this->actingAs($this->cashier)->get('/cashier');

        $response->assertOk()
            ->assertDontSee('Empty Containers Returned', false)
            ->assertDontSee('Filled OUT', false)
            ->assertDontSee('Damaged / cracked bottle', false)
            ->assertDontSee('returns-table', false)
            ->assertDontSee('returns-row', false)
            ->assertDontSee('Container Shape', false);
    }

    public function test_new_jugs_are_sold_as_retail_stock_not_a_deposit(): void
    {
        $response = $this->actingAs($this->cashier)->get('/cashier');

        $response->assertOk()
            // Selling a jug is ordinary merchandise, not a custody deposit.
            ->assertDontSee('Deposit', false);

        $cart = $this->js('js/pos/cart.js');

        $this->assertStringContainsString('Available stock: ', $cart);
        $this->assertStringContainsString('Walk-in only', $cart);
        $this->assertStringContainsString('For Sale Brand New', $cart);
    }

    public function test_stage_three_has_inclusive_vat_and_no_discounts(): void
    {
        $response = $this->actingAs($this->cashier)->get('/cashier');

        $response->assertOk()
            ->assertSee('Order Summary', false)
            ->assertSee('Vatable Sales', false)
            ->assertSee('12% VAT (inclusive)', false)
            ->assertSee('id="tTot"', false)
            ->assertSee('id="tVatable"', false)
            ->assertSee('id="tVat"', false)
            // Discounts and the old subtotal line are gone.
            ->assertDontSee('Auto Return Discount', false)
            ->assertDontSee('Discount', false)
            ->assertDontSee('Subtotal', false)
            ->assertDontSee('jugBanner', false);
    }

    public function test_stage_three_offers_cash_for_walk_ins_and_account_for_deliveries(): void
    {
        $response = $this->actingAs($this->cashier)->get('/cashier');

        $response->assertOk()
            ->assertSee('id="cashPanel"', false)
            ->assertSee('id="deliveryPanel"', false)
            ->assertSee('id="tender"', false)
            ->assertSee('id="tChg"', false)
            ->assertSee('Amount Tendered', false)
            ->assertSee('Exact', false)
            // The delivery panel explains the automatic charge.
            ->assertSee('Automatically charged to customer account', false)
            ->assertSee('id="deliveryBalance"', false)
            // There is no payment-method picker any more.
            ->assertDontSee('GCASH', false)
            ->assertDontSee('setPay(', false);

        $checkout = (string) file_get_contents(base_path('public/js/pos/checkout.js'));

        $this->assertStringContainsString('Complete & Print Receipt', $checkout);
        $this->assertStringContainsString('Save Delivery & Print Slip', $checkout);
    }

    public function test_the_receipt_only_ever_prints_the_receipt(): void
    {
        $this->actingAs($this->cashier)->get('/cashier')
            ->assertOk()
            ->assertSee('printReceipt()', false)
            ->assertSee('id="rBody"', false);

        $css = (string) file_get_contents(base_path('public/css/styles.css'));
        $this->assertStringContainsString('printing-receipt', $css);
    }

    public function test_all_three_stages_ship_in_one_document(): void
    {
        $response = $this->actingAs($this->cashier)->get('/cashier');

        $response->assertOk();

        // All stages ship in one document (no page reloads mid-sale); the
        // terminal displays one at a time.
        foreach (['data-step="1"', 'data-step="2"', 'data-step="3"'] as $stage) {
            $response->assertSee($stage, false);
        }
    }

    // ---------- The cashier sidebar ----------

    public function test_the_cashier_sidebar_links_the_pages(): void
    {
        $response = $this->actingAs($this->cashier)->get('/cashier');

        $response->assertOk()
            ->assertSee('POS Terminal', false)
            ->assertSee('Orders in Progress', false)
            ->assertSee('Sales &amp; Receipts', false)
            ->assertSee('Stock Availability', false)
            ->assertSee('/cashier/queue', false)
            ->assertSee('/cashier/history', false)
            ->assertSee('/cashier/stocks', false);
    }

    public function test_the_queue_page_lists_the_production_line(): void
    {
        $this->actingAs($this->cashier)->get('/cashier/queue')
            ->assertOk()
            ->assertSee('Orders in Progress', false)
            ->assertSee('Active orders', false)
            ->assertSee('Delivered', false)
            ->assertSee('id="queue"', false)
            ->assertDontSee('id="stageGuide"', false)
            // The "Production Line" explainer banner was removed.
            ->assertDontSee('Production Line', false)
            ->assertDontSee('Advance each order', false);
    }

    public function test_the_history_page_lists_transactions_with_the_vat_split(): void
    {
        $this->actingAs($this->cashier)->get('/cashier/history')
            ->assertOk()
            ->assertSee('Sales &amp; Receipts', false)
            ->assertSee('Vatable', false)
            ->assertSee('VAT', false)
            ->assertSee('id="hBody"', false)
            ->assertSee('id="hFrom"', false)
            ->assertSee('Debt Payment', false);
    }

    public function test_admin_sidebar_opens_the_active_group_and_preserves_role_links(): void
    {
        foreach ([$this->manager, $this->owner] as $user) {
            foreach (['dashboard', 'inventory', 'arima', 'users'] as $tab) {
                $response = $this->actingAs($user)->get('/admin/'.$tab)->assertOk();
                $document = new \DOMDocument;
                @$document->loadHTML($response->getContent());
                $xpath = new \DOMXPath($document);
                $this->assertSame(5, $xpath->query('//nav[@id="sideNav"]/details[@name="admin-navigation"]')->length);
                $this->assertSame($tab === 'dashboard' ? 0 : 1, $xpath->query('//nav[@id="sideNav"]/details[@open]')->length);
                $overview = $xpath->query('//nav[@id="sideNav"]/*[1][self::a and @data-s="dashboard"]');
                $this->assertSame(1, $overview->length);
                $active = $xpath->query('//nav[@id="sideNav"]//a[@aria-current="page"]');
                $this->assertSame(1, $active->length);
                $this->assertSame('/admin/'.$tab, parse_url($active->item(0)->getAttribute('href'), PHP_URL_PATH));
                $settings = $xpath->query('//nav[@id="sideNav"]//a[@data-s="settings"]');
                $this->assertSame($user->isSuperAdmin() ? 1 : 0, $settings->length);
            }
        }
    }

    public function test_management_cash_drawers_use_a_read_only_register_and_cashier_keeps_count_forms(): void
    {
        foreach ([$this->manager, $this->owner] as $user) {
            $this->actingAs($user)->get('/admin/cash')->assertOk()
                ->assertSee('id="cashOverviewSearch"', false)
                ->assertSee('id="cashOverviewStatus"', false)
                ->assertSee('data-overview="1"', false)
                ->assertDontSee('id="cashOpenForm"', false)
                ->assertDontSee('id="cashCloseForm"', false);
        }
        $this->actingAs($this->cashier)->get('/cashier/cash')->assertOk()
            ->assertSee('id="cashOpenForm"', false)
            ->assertSee('id="cashCounted"', false)
            ->assertDontSee('id="cashOverviewSearch"', false);
    }

    public function test_admin_delivery_panels_monitor_and_cashier_keeps_physical_operations(): void
    {
        foreach ([$this->manager, $this->owner] as $user) {
            foreach (['/admin/dashboard', '/admin/orders'] as $url) {
                $this->actingAs($user)->get($url)->assertOk()
                    ->assertSee('data-mode="monitor"', false)
                    ->assertSee('Delivery monitoring')
                    ->assertDontSee('Walk-in handover &amp; order history', false);
            }
        }
        $this->actingAs($this->cashier)->get('/cashier/queue')->assertOk()
            ->assertSee('data-mode="operations"', false);
    }

    public function test_signed_in_users_return_to_their_workspace_instead_of_a_second_login(): void
    {
        $this->get('/')->assertOk()->assertSee('id="adminForm"', false);
        foreach (User::whereIn('username', ['manager', 'admin', 'cashier', 'rider', 'refiller'])->get() as $user) {
            $this->actingAs($user)->get('/')->assertRedirect($user->homeUrl());
        }
    }

    public function test_each_sidebar_page_marks_itself_active(): void
    {
        $pages = [
            '/cashier' => 'POS Terminal',
            '/cashier/queue' => 'Orders in Progress',
            '/cashier/history' => 'Sales &amp; Receipts',
            '/cashier/stocks' => 'Stock Availability',
        ];

        foreach ($pages as $url => $marker) {
            $response = $this->actingAs($this->cashier)->get($url)->assertOk()->assertSee($marker, false);
            $document = new \DOMDocument;
            @$document->loadHTML($response->getContent());
            $active = (new \DOMXPath($document))->query('//nav[@id="sideNav"]//a[@aria-current="page"]');
            $this->assertSame(1, $active->length);
            $this->assertSame($url, parse_url($active->item(0)->getAttribute('href'), PHP_URL_PATH));
        }
    }

    public function test_the_cashier_has_no_route_to_the_owner_portal(): void
    {
        // Shared assets may live under js/admin; navigation must never expose owner routes.
        foreach (['/cashier', '/cashier/queue', '/cashier/history', '/cashier/stocks'] as $url) {
            $response = $this->actingAs($this->cashier)->get($url)
                ->assertOk()->assertDontSee('Owner Portal', false);
            $document = new \DOMDocument;
            @$document->loadHTML($response->getContent());
            foreach ((new \DOMXPath($document))->query('//a[@href] | //form[@action]') as $element) {
                $target = $element->getAttribute($element->tagName === 'form' ? 'action' : 'href');
                $this->assertDoesNotMatchRegularExpression('~^/admin(?:/|$)~', parse_url($target, PHP_URL_PATH) ?? '');
            }
        }
        $this->get('/admin/dashboard')->assertRedirect('/cashier');
        $this->getJson('/api/users')->assertForbidden();
    }

    public function test_the_cashier_subpages_have_no_back_to_pos_button(): void
    {
        foreach (['/cashier/queue', '/cashier/history', '/cashier/stocks'] as $url) {
            $this->actingAs($this->cashier)->get($url)
                ->assertOk()
                ->assertDontSee('Back to POS Terminal', false);
        }
    }

    public function test_each_stage_validates_its_content_before_advancing(): void
    {
        // The message slot lives with the stage buttons, so it is always in view
        // at the moment the cashier tries to move on.
        $this->actingAs($this->cashier)->get('/cashier')
            ->assertOk()
            ->assertSee('id="posStageError"', false)
            ->assertSee('role="alert"', false)
            ->assertSee('onclick="posNext()"', false);

        $checkout = (string) file_get_contents(base_path('public/js/pos/checkout.js'));

        // Stage 1 needs a customer; stage 2 needs at least one item.
        $this->assertStringContainsString('Select a customer before continuing.', $checkout);
        $this->assertStringContainsString('Add an item and wait for a successful stock check before continuing.', $checkout);
        $this->assertStringContainsString('function validateStage', $checkout);
        $this->assertStringContainsString('function stageProblem', $checkout);

        // Next and every forward tab jump go through the gate.
        $this->assertStringContainsString('if (!validateStage(posCur)) return;', $checkout);
        $this->assertMatchesRegularExpression('/if \(!force && target > posCur\)/', $checkout);

        // Completing a sale reports through the same inline message.
        $this->assertStringNotContainsString("alert('Select a customer first.')", $checkout);
        $this->assertStringContainsString('showStageError(', $checkout);
    }

    public function test_a_fully_blocked_stage_keeps_the_cashier_put(): void
    {
        // Server side, the same two rules are enforced no matter what the
        // client does: no customer, and an empty cart.
        $this->actingAs($this->cashier)
            ->postJson('/api/transactions', ['submission_key' => (string) Str::uuid(),
                'customer_id' => 999999,
                'order_type' => 'Walk-in',
                'payment_method' => 'Cash',
                'cash_tendered' => 500,
                'items' => [['product_id' => 'slim', 'quantity' => 1]],
            ])
            ->assertStatus(422);

        $this->actingAs($this->cashier)
            ->postJson('/api/transactions', ['submission_key' => (string) Str::uuid(),
                'customer_id' => 9,
                'order_type' => 'Walk-in',
                'payment_method' => 'Cash',
                'cash_tendered' => 500,
                'items' => [],
            ])
            ->assertStatus(422);
    }

    public function test_the_terminal_is_a_non_scrolling_viewport_app(): void
    {
        // The shell locks to the viewport; only designated regions scroll.
        $this->actingAs($this->cashier)->get('/cashier')
            ->assertOk()
            ->assertSee('cashier-viewport', false)
            ->assertSee('id="posStageError"', false);

        $css = (string) file_get_contents(base_path('public/css/styles.css'));

        // The shell is pinned to 100vh and hides page overflow.
        $this->assertMatchesRegularExpression(
            '/\.cashier-viewport[^{]*\{[^}]*height:\s*100vh[^}]*max-height:\s*100vh[^}]*overflow:\s*hidden/s',
            $css
        );

        // Micro-scrolls are allowed, but only inside marked regions.
        $this->assertMatchesRegularExpression(
            '/\.scroll-region\s*\{[^}]*overflow-y:\s*auto/s',
            $css
        );

        // The action bar is docked as a flex child, not an overlay.
        $this->assertMatchesRegularExpression(
            '/\.mobile-action-bar\s*\{[^}]*flex:\s*0 0 auto/s',
            $css
        );
        $this->assertStringNotContainsString("position: fixed;\n  left: 0;\n  right: 0;\n  bottom: 0;", $css);
    }

    public function test_only_the_terminal_locks_to_the_viewport(): void
    {
        // The queue and history pages share the cashier shell but must keep
        // normal page scrolling; locking them would clip their content.
        $this->actingAs($this->cashier)->get('/cashier')
            ->assertOk()
            ->assertSee('admin-layout cashier-viewport', false);

        foreach (['/cashier/queue', '/cashier/history'] as $url) {
            $this->actingAs($this->cashier)->get($url)
                ->assertOk()
                ->assertSee('admin-layout', false)
                ->assertDontSee('admin-layout cashier-viewport', false);
        }
    }

    public function test_stage_cards_and_lists_scroll_internally(): void
    {
        $this->actingAs($this->cashier)->get('/cashier')
            ->assertOk()
            // The customer list and the cart are the scrollable regions.
            ->assertSee('class="customer-list-container scroll-region"', false)
            ->assertSee('class="cart-items-container scroll-region"', false);
    }

    // ---------- Navigation guards ----------

    public function test_legacy_wizard_urls_redirect_to_the_terminal(): void
    {
        foreach (['customer-custody', 'products-intake', 'payment-print'] as $step) {
            $this->actingAs($this->cashier)
                ->get('/cashier/'.$step)
                ->assertRedirect('/cashier');
        }
    }

    public function test_a_cashier_is_redirected_away_from_the_owner_portal(): void
    {
        $this->actingAs($this->cashier)
            ->get('/admin/dashboard')
            ->assertRedirect('/cashier');
    }

    public function test_the_owner_can_open_every_dashboard_tab(): void
    {
        $this->actingAs($this->owner)->get('/admin')->assertRedirect('/admin/dashboard');

        // The sidebar shows a plain-language label for station owners, with the
        // precise thesis term kept underneath it in a .sidebar-link-hint.
        $tabs = [
            'dashboard' => 'Overview',
            'sales' => 'Sales History',
            'arima' => 'Demand Forecast',
            'inventory' => 'Stock &amp; Prices',
            'customers' => 'Customer Balances',
            'suppliers' => 'Suppliers',
            'users' => 'Team &amp; Access',
            'settings' => 'Settings',
        ];

        foreach ($tabs as $tab => $marker) {
            $this->actingAs($this->owner)
                ->get('/admin/'.$tab)
                ->assertOk()
                ->assertSee($marker, false);
        }

        // The technical terms the thesis relies on must stay visible to a reviewer.
        $this->actingAs($this->owner)
            ->get('/admin/arima')
            ->assertSee('ARIMA model', false);
        $this->actingAs($this->owner)
            ->get('/admin/inventory')
            ->assertSee('reorder point', false);
    }

    public function test_the_owner_can_also_open_the_terminal(): void
    {
        $this->actingAs($this->owner)->get('/cashier')->assertOk();
    }

    public function test_unknown_tabs_and_steps_are_not_found(): void
    {
        $this->actingAs($this->owner)->get('/admin/whatever')->assertNotFound();
        $this->actingAs($this->cashier)->get('/cashier/whatever')->assertNotFound();
    }

    public function test_pages_render_the_shared_shell(): void
    {
        $response = $this->actingAs($this->owner)->get('/admin/dashboard');

        // The sidebar and CSRF token come from the layout, not the page.
        $response->assertSee('sidebar-nav', false);
        $response->assertSee('csrf-token', false);
        $response->assertSee('Super Admin');
    }

    public function test_the_health_endpoint_reports_the_database(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('db', true);
    }

    public function test_the_approval_and_audit_pages_render_for_a_manager(): void
    {
        // A manager must reach both screens: the approval list is how a manager
        // settles a cashier's collection, and the audit log is how they review
        // changes and see who approved what. The owner sees the same pages.
        foreach ([$this->manager, $this->owner] as $viewer) {
            $approvals = $this->actingAs($viewer)->get('/admin/approvals')->assertOk();
            $approvals->assertSee('id="approvalList"', false)
                ->assertSee('Approval Requests')
                ->assertSee('js/admin/approvals.js', false);

            $audit = $this->actingAs($viewer)->get('/admin/audit-logs')->assertOk();
            $audit->assertSee('id="auditList"', false)
                ->assertSee('Audit Log')
                ->assertSee('js/admin/audit-logs.js', false);
        }

        // The sidebar carries both entries.
        $this->actingAs($this->manager)->get('/admin/dashboard')
            ->assertOk()
            ->assertSee('/admin/approvals', false)
            ->assertSee('/admin/audit-logs', false);
    }

    public function test_tables_have_search_and_chronological_sort_controls(): void
    {
        $pages = [
            '/admin/dashboard' => ['invBodyDash', 'topLiaBody'],
            '/admin/inventory' => ['invBody'],
            '/admin/customers' => ['custBody'],
            '/admin/users' => ['usersBody'],
            '/admin/sales' => ['salesBody'],
            '/admin/suppliers' => ['supGrid'],
            '/cashier/history' => ['hBody'],
        ];

        foreach ($pages as $url => $targets) {
            $response = $this->actingAs($this->owner)->get($url)->assertOk();
            foreach ($targets as $target) {
                $response->assertSee('id="'.$target.'Search"', false)
                    ->assertSee('id="'.$target.'Order"', false);
                if (in_array($target, ['invBodyDash', 'invBody'])) {
                    $response->assertSee('Least to most stock')->assertSee('Most to least stock');
                } else {
                    $response->assertSee('New to Old')->assertSee('Old to New');
                }
            }
        }
    }

    public function test_add_forms_start_closed_and_have_accessible_toggle_buttons(): void
    {
        foreach (['inventory' => 'invForm', 'suppliers' => 'supForm', 'users' => 'userForm'] as $page => $form) {
            $this->actingAs($this->owner)->get('/admin/'.$page)->assertOk()
                ->assertSee('aria-expanded="false" aria-controls="'.$form.'Panel"', false)
                ->assertSee('id="'.$form.'Panel" class="card form-card hidden"', false)
                ->assertSee('onclick="toggleAddForm(this)"', false);
        }
    }

    public function test_u_s23_u_s33_u_s48_dashboard_uses_the_approved_msme_model(): void
    {
        $this->actingAs($this->owner)->get('/admin/dashboard')->assertOk()
            ->assertSee('Sales Revenue Today')->assertSee('New Slim Jugs')->assertSee('New Round Jugs')
            ->assertSee('Outstanding Customer Balances')->assertDontSee('Pending Bottles (All)');
    }

    public function test_u_s40_u_s49_u_s50_forecast_views_and_exports_are_available(): void
    {
        $this->actingAs($this->owner)->get('/admin/arima')->assertOk()
            ->assertSee('Daily')->assertSee('Weekly')->assertSee('Monthly')->assertSee('Print / Save PDF')
            ->assertSee('saveForecastView()', false)->assertSee('exportReport()', false);
    }

    public function test_u_s47_forecasts_are_scheduled_at_two_am(): void
    {
        $events = app(Schedule::class)->events();
        $forecast = array_values(array_filter($events, fn ($event) => str_contains($event->command ?? '', 'aquaflow:forecast')));
        $this->assertCount(1, $forecast);
        $this->assertSame('0 2 * * *', $forecast[0]->expression);
    }

    // --- From BladeIntegrityTest.php ---
    public function test_portal_pages_render_with_unique_ids_and_existing_local_assets(): void
    {
        $this->seed();
        $owner = User::where('role', 'super_admin')->firstOrFail();
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $pages = array_map(fn ($page) => ['/admin/'.$page, $owner], ['dashboard', 'orders', 'sales', 'arima', 'inventory', 'customers', 'suppliers', 'users', 'settings', 'cash', 'defects', 'approvals', 'audit-logs']);
        $pages = [...$pages, ['/cashier', $cashier], ['/cashier/queue', $cashier], ['/cashier/history', $cashier], ['/cashier/stocks', $cashier], ['/cashier/cash', $cashier], ['/employee', User::where('role', 'refiller')->firstOrFail()]];
        foreach ($pages as [$url, $user]) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('css/styles.css?v=', $html, $url.' must use the shared palette');
            $document = new \DOMDocument;
            @$document->loadHTML($html);
            $ids = [];
            foreach ((new \DOMXPath($document))->query('//*[@id]') as $element) {
                $ids[] = $element->getAttribute('id');
            }
            $this->assertSame([], array_filter(array_count_values($ids), fn ($count) => $count > 1), $url.' has duplicate IDs');
            preg_match_all('/(?:src|href)="([^" ]+\.(?:js|css)(?:\?[^" ]*)?)"/', $html, $assets);
            foreach ($assets[1] as $asset) {
                $path = parse_url(html_entity_decode($asset), PHP_URL_PATH);
                $this->assertFileExists(public_path(ltrim($path, '/')), $url.' references missing asset '.$path);
            }
        }
    }

    public function test_admin_login_keeps_removed_controls_absent_and_versions_its_script(): void
    {
        $this->get('/')->assertOk()->assertDontSee('Use a recovery code')->assertDontSee('Start again with different details')->assertSee('admin-login.js?v=', false);
    }

    public function test_pos_has_a_native_customer_dialog_stock_retry_and_read_only_steps(): void
    {
        $this->seed();
        $html = $this->actingAs(User::where('role', 'cashier')->firstOrFail())->get('/cashier')->assertOk()->getContent();
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//dialog[@id="registerModal"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="stockPreview"]')->length);
        $this->assertSame(0, $xpath->query('//*[@id="posSteps"]//*[@onclick]')->length);
    }

    // --- From ReleaseReadinessTest.php ---
    private function owner(): User
    {
        return User::create(['name' => 'Owner', 'username' => 'owner', 'password' => 'StrongPass123', 'role' => 'super_admin']);
    }

    public function test_customer_requires_a_deliverable_mobile_number(): void
    {
        $this->actingAs($this->owner());
        foreach ([null, '', '-', 'abc', '021234567', '091234567890', ['09123456789']] as $contact) {
            $this->postJson('/api/customers', ['name' => 'Ana Santos', 'contact' => $contact])
                ->assertUnprocessable()->assertJsonValidationErrors('contact');
        }
        $this->postJson('/api/customers', ['name' => 'Ana Santos', 'contact' => '09123456789'])->assertCreated();
        $this->assertDatabaseHas('customers', ['name' => 'Ana Santos', 'contact' => '+639123456789']);
        $this->postJson('/api/customers', ['name' => 'Ana Santos', 'contact' => '+63 912-345-6789'])->assertCreated();
    }

    public function test_profile_name_boundary_does_not_partially_write(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->putJson('/api/profile', ['name' => str_repeat('A', 21)])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame('Owner', $owner->fresh()->name);
        $this->putJson('/api/profile', ['name' => str_repeat('A', 20)])->assertOk();
    }

    public function test_new_cashier_must_replace_default_password_before_work(): void
    {
        $this->actingAs($this->owner());
        $id = $this->postJson('/api/users', ['name' => 'Ana Santos', 'username' => 'ana', 'role' => 'cashier', 'email' => 'ana@example.com'])
            ->assertCreated()->assertJsonPath('user.must_change_password', true)->json('user.id');
        $cashier = User::findOrFail($id);
        $this->assertTrue(Hash::check('12345678', $cashier->password));
        auth()->logout();
        $this->postJson('/api/auth/login', ['username' => 'ana', 'password' => '12345678'])
            ->assertOk()->assertJsonPath('user.must_change_password', true);
        $this->getJson('/api/customers')->assertForbidden()->assertJsonPath('code', 'password_change_required');
        $this->getJson('/api/profile')->assertOk();
        $this->postJson('/api/transactions', [])->assertForbidden()->assertJsonPath('code', 'password_change_required');
        $this->putJson('/api/profile', ['name' => 'Ana Santos'])->assertUnprocessable()->assertJsonValidationErrors('new_password');
        $this->putJson('/api/profile', ['name' => 'Ana Santos', 'current_password' => '12345678', 'new_password' => 'ChangedPass123', 'new_password_confirmation' => 'ChangedPass123'])
            ->assertOk()->assertJsonPath('user.must_change_password', false);
        $this->getJson('/api/customers')->assertOk();
        $this->assertTrue(Hash::check('ChangedPass123', $cashier->fresh()->password));
    }

    public function test_staff_name_limits_and_admin_password_validation(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->postJson('/api/users', ['name' => str_repeat('A', 21), 'username' => 'new_staff', 'role' => 'cashier'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson('/api/users/'.$owner->id, ['name' => str_repeat('A', 21)])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame('Owner', $owner->fresh()->name);
        $this->postJson('/api/users', ['name' => str_repeat('A', 20), 'username' => 'new_admin', 'role' => 'admin', 'email' => 'newadmin@example.com', 'password' => '12345678'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_unsupported_roles_cannot_be_created_or_signed_in(): void
    {
        $owner = $this->owner();
        foreach (['manager'] as $role) {
            $this->actingAs($owner)->postJson('/api/users', ['name' => 'Staff', 'username' => $role, 'role' => $role, 'password' => 'StrongPass123'])
                ->assertUnprocessable()->assertJsonValidationErrors('role');
            if ($role === 'manager') {
                continue;
            }
            User::create(['name' => 'Staff', 'username' => $role, 'role' => $role, 'password' => 'StrongPass123']);
            auth()->logout();
            $this->postJson('/api/auth/login', ['username' => $role, 'password' => 'StrongPass123'])
                ->assertUnauthorized()->assertExactJson(['message' => 'Invalid username or password.']);
        }
        $this->actingAs($owner)->getJson('/api/users')->assertOk()->assertJsonCount(1, 'users');
    }

    // --- From SourceEncodingTest.php ---
    /** Directories whose files must not carry a byte-order mark. */
    private const SCANNED = ['app', 'resources', 'routes', 'config', 'database', 'public', 'tests'];

    private const EXTENSIONS = ['php', 'js', 'cjs', 'css', 'json', 'md', 'yml'];

    public function test_no_source_file_starts_with_a_utf8_byte_order_mark(): void
    {
        $offenders = [];

        foreach (self::SCANNED as $directory) {
            $path = base_path($directory);
            if (! is_dir($path)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), self::EXTENSIONS, true)) {
                    continue;
                }

                $handle = fopen($file->getPathname(), 'rb');
                $head = fread($handle, 3);
                fclose($handle);

                if ($head === "\xEF\xBB\xBF") {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'UTF-8 BOM found in: '.implode(', ', $offenders).'. Re-save as UTF-8 without BOM.'
        );
    }

    public function test_the_cashier_portal_response_starts_with_the_doctype(): void
    {
        $this->seed();
        $user = User::where('role', 'cashier')->firstOrFail();

        $body = $this->actingAs($user)->get('/cashier')->assertOk()->getContent();

        $this->assertStringStartsWith(
            '<!DOCTYPE html>',
            ltrim($body, "\xEF\xBB\xBF"),
            'The cashier portal served bytes ahead of its DOCTYPE.'
        );
    }
}
