<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DeliveryProof;
use App\Models\Employee;
use App\Models\ProductionQueueItem;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Database\Seeders\EmployeeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationsAndFulfillmentFeatureTest extends TestCase
{
    use RefreshDatabase;

    private ?User $cashier = null;
    private ?User $rider = null;
    private ?Customer $buyer = null;
    private ?ProductionQueueItem $job = null;

    protected function setUp(): void
    {
        parent::setUp();

        $accountabilityTests = [
            'test_cashier_handover_is_required_and_code_is_hidden_from_rider',
            'test_delivery_requires_valid_photo_buyer_code_quantity_and_cash_declaration',
            'test_delivery_and_cash_report_do_not_settle_debt_until_cashier_accepts_remittance',
            'test_short_remittance_requires_explanation_and_keeps_the_remainder_due',
            'test_proofs_are_private_and_riders_cannot_accept_their_own_remittance',
            'test_remittance_needs_no_drawer_and_conflicting_retries_are_rejected',
            'test_exact_photo_reuse_is_rejected_and_delivery_retry_cannot_change_cash',
            'test_partial_collection_keeps_uncollected_debt_and_replacement_codes_invalidate_old_ones',
            'test_history_remains_reviewable_and_referenced_staff_cannot_be_deleted',
            'test_prepaid_deliveries_cannot_create_a_second_cash_collection',
            'test_unreserved_debt_can_be_paid_without_consuming_pending_rider_cash',
            'test_two_cashiers_share_one_remittance_balance_and_get_only_the_cash_they_accept',
            'test_tiny_oversized_and_script_images_are_rejected_without_storing_files',
            'test_negative_and_fractional_cent_cash_is_rejected',
            'test_a_rider_cannot_reset_the_buyer_code_or_confirm_cashier_handover',
            'test_a_delivery_cannot_be_completed_before_dispatch',
            'test_buyer_code_guessing_is_rate_limited',
            'test_a_failure_after_photo_upload_rolls_back_evidence_and_removes_the_file',
        ];

        $employeeTests = [
            'test_owner_can_manage_employees_without_creating_login_accounts',
            'test_employee_records_have_no_screen_of_their_own',
            'test_employee_validation_and_owner_permissions',
            'test_cashier_can_handover_orders_and_retries_are_idempotent',
        ];

        if (in_array($this->name(), $accountabilityTests, true)) {
            $this->seed();
            Storage::fake('local');
            $this->cashier = User::where('role', 'cashier')->first();
            $this->rider = User::where('role', 'rider')->first();
            $this->buyer = Customer::whereNull('system_key')->first();
            if ($this->buyer && $this->cashier && $this->rider) {
                $this->buyer->update(['debt_balance' => 100]);
                $sale = Transaction::create([
                    'receipt_number' => 'DELIVERY-QA',
                    'customer_id' => $this->buyer->id,
                    'cashier_id' => $this->cashier->id,
                    'order_type' => 'Delivery',
                    'payment_method' => 'Account',
                    'total_amount' => 100,
                    'payment_status' => 'unpaid',
                    'transaction_date' => today(),
                    'transaction_time' => '08:00:00',
                ]);
                TransactionItem::create([
                    'transaction_id' => $sale->id,
                    'product_id' => 'slim',
                    'item_name' => 'Refill',
                    'quantity' => 2,
                    'unit_price' => 50,
                    'line_total' => 100,
                ]);
                $this->job = ProductionQueueItem::create([
                    'transaction_id' => $sale->id,
                    'receipt_number' => $sale->receipt_number,
                    'customer_name' => $this->buyer->name,
                    'items_description' => '2 refills',
                    'order_type' => 'Delivery',
                ]);
                $this->job->forceFill(['rider_id' => $this->rider->id, 'fulfillment_status' => 'ready'])->save();
            }
        } elseif (in_array($this->name(), $employeeTests, true)) {
            $this->seed();
        }
    }

    // --- From FulfillmentTest.php ---
    public function test_assigned_rider_delivery_is_ordered_idempotent_and_never_deducts_stock(): void
    {
        $this->seed();
        $employee = Employee::where('name', 'Carla Mendoza')->firstOrFail();
        $rider = User::create(['username' => 'qa_rider', 'name' => 'Rider', 'password' => 'Rider123', 'role' => 'rider', 'employee_id' => $employee->id, 'is_active' => true]);
        $job = ProductionQueueItem::create(['receipt_number' => 'QA-DELIVERY', 'customer_name' => 'Customer', 'items_description' => '1 refill', 'order_type' => 'Delivery']);
        $job->forceFill(['order_type' => 'Delivery', 'fulfillment_status' => 'ready', 'rider_id' => $rider->id])->save();
        $customer = Customer::whereNull('system_key')->firstOrFail();
        $customer->update(['debt_balance' => 100]);
        $transaction = Transaction::create(['receipt_number' => 'QA-DELIVERY', 'customer_id' => $customer->id, 'cashier_id' => User::where('role', 'cashier')->firstOrFail()->id, 'order_type' => 'Delivery', 'payment_method' => 'Account', 'total_amount' => 100, 'transaction_date' => today(), 'transaction_time' => '08:00:00']);
        Storage::fake('local');
        TransactionItem::create(['transaction_id' => $transaction->id, 'product_id' => 'slim', 'item_name' => 'Refill', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]);
        $job->forceFill(['transaction_id' => $transaction->id, 'handed_over_by' => $transaction->cashier_id, 'handed_over_at' => now(), 'delivery_code' => '12345678'])->save();
        $stock = StockMovement::count();
        $this->actingAs($rider);
        $payload = ['action' => 'dispatch', 'version' => 0, 'submission_key' => (string) Str::uuid()];
        $this->postJson('/api/employee/orders/'.$job->id, $payload)->assertOk();
        $this->postJson('/api/employee/orders/'.$job->id, $payload)->assertOk();
        $this->assertSame(1, $job->fresh()->version);
        $this->post('/api/employee/orders/'.$job->id, ['action' => 'deliver', 'version' => 1, 'submission_key' => (string) Str::uuid(),
            'photo' => UploadedFile::fake()->createWithContent('delivery.png', file_get_contents(base_path('tests/Fixtures/delivery.png'))),
            'recipient_name' => 'Buyer', 'recipient_code' => '12345678', 'received_quantity' => 1, 'reported_cash' => 0, 'collection_note' => 'Customer will pay at station'], ['Accept' => 'application/json'])->assertOk();
        $this->assertTrue($job->fresh()->is_completed);
        $this->assertEquals(100, $customer->fresh()->debt_balance);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertSame($stock, StockMovement::count());
        $this->assertDatabaseCount('fulfillment_events', 2);
        foreach (['super_admin', 'cashier'] as $role) {
            $this->actingAs(User::where('role', $role)->firstOrFail())
                ->getJson('/api/queue?status=delivered')->assertOk()
                ->assertJsonFragment(['id' => $job->id, 'completed' => true]);
        }
    }

    public function test_rider_cannot_skip_an_older_unready_order_and_sees_global_ready_orders(): void
    {
        $this->seed();
        $rider = User::create(['username' => 'qa_rider', 'name' => 'Rider', 'password' => 'Rider123', 'role' => 'rider', 'employee_id' => Employee::where('name', 'Carla Mendoza')->firstOrFail()->id, 'is_active' => true]);
        $older = ProductionQueueItem::create(['receipt_number' => 'FIFO-1', 'customer_name' => 'Customer', 'items_description' => '1 refill', 'order_type' => 'Delivery']);
        $newer = ProductionQueueItem::create(['receipt_number' => 'FIFO-2', 'customer_name' => 'Customer', 'items_description' => '1 refill', 'order_type' => 'Delivery']);
        $older->forceFill(['rider_id' => $rider->id])->save();
        $newer->forceFill(['rider_id' => $rider->id, 'fulfillment_status' => 'ready'])->save();
        $this->actingAs($rider)->postJson('/api/employee/orders/'.$newer->id, ['action' => 'dispatch', 'version' => 0, 'submission_key' => (string) Str::uuid()])->assertUnprocessable();
        $newer->forceFill(['rider_id' => null])->save();
        $this->getJson('/api/employee/orders')->assertOk()->assertJsonCount(2, 'orders')->assertDontSee('debt_balance')->assertDontSee('total_amount');
        $this->postJson('/api/employee/orders/'.$newer->id, ['action' => 'dispatch', 'version' => 0, 'submission_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson('/api/employee/orders/'.$older->id, ['action' => 'ready', 'version' => 0, 'submission_key' => (string) Str::uuid()])->assertForbidden();
        $this->assertDatabaseCount('fulfillment_events', 0);
    }

    public function test_refiller_readiness_validates_version_and_records_the_actor(): void
    {
        $this->seed();
        $refiller = User::where('role', 'refiller')->firstOrFail();
        $job = ProductionQueueItem::create(['receipt_number' => 'REFILL-QA', 'customer_name' => 'Customer', 'items_description' => '1 refill', 'order_type' => 'Walk-in']);
        $payload = ['action' => 'ready', 'version' => '0', 'submission_key' => (string) Str::uuid()];
        $this->actingAs($refiller)->postJson('/api/employee/orders/'.$job->id, $payload)->assertOk();
        $this->postJson('/api/employee/orders/'.$job->id, $payload)->assertOk();
        $this->assertSame(ProductionQueueItem::FINAL_STAGE, $job->fresh()->stage);
        $this->assertSame(1, $job->fresh()->version);
        $this->assertDatabaseHas('fulfillment_events', ['production_queue_id' => $job->id, 'user_id' => $refiller->id, 'action' => 'ready']);
        $this->postJson('/api/employee/orders/'.$job->id, ['action' => 'ready', 'version' => 0, 'submission_key' => (string) Str::uuid()])->assertConflict();
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $this->actingAs($cashier)->postJson('/api/queue/'.$job->id.'/deliver')->assertOk();
        $this->assertTrue($job->fresh()->is_completed);
    }

    public function test_ready_delivery_is_shared_until_one_rider_dispatches_it(): void
    {
        $this->seed();
        $rider = User::where('role', 'rider')->firstOrFail();
        $otherEmployee = Employee::create(['name' => 'Second Rider', 'job_title' => 'Delivery Rider', 'is_active' => true]);
        $otherRider = User::create(['username' => 'qa_second_rider', 'name' => 'Second Rider', 'password' => 'Rider123', 'role' => 'rider', 'employee_id' => $otherEmployee->id, 'is_active' => true]);
        $customer = Customer::whereNull('system_key')->firstOrFail();
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $transaction = Transaction::create(['receipt_number' => 'SHARED-READY', 'customer_id' => $customer->id, 'cashier_id' => $cashier->id, 'order_type' => 'Delivery', 'payment_method' => 'Account', 'total_amount' => 100, 'transaction_date' => today(), 'transaction_time' => '10:00:00']);
        TransactionItem::create(['transaction_id' => $transaction->id, 'product_id' => 'slim', 'item_name' => 'Refill', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]);
        $job = ProductionQueueItem::create(['transaction_id' => $transaction->id, 'receipt_number' => 'SHARED-READY', 'customer_name' => 'Customer', 'items_description' => '1 refill', 'order_type' => 'Delivery']);

        $this->actingAs(User::where('role', 'refiller')->firstOrFail())
            ->postJson('/api/employee/orders/'.$job->id, ['action' => 'ready', 'version' => 0, 'submission_key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('order.rider_id', null);
        $this->assertNull($job->fresh()->rider_id);
        $this->actingAs($rider)->getJson('/api/employee/orders')->assertOk()->assertJsonFragment(['id' => $job->id]);
        $this->actingAs($otherRider)->getJson('/api/employee/orders')->assertOk()->assertJsonFragment(['id' => $job->id]);
        $this->actingAs($cashier)->postJson('/api/fulfillment/'.$job->id, ['action' => 'handover', 'version' => 1, 'submission_key' => (string) Str::uuid(), 'handover_quantity' => 1])->assertOk();
        $this->actingAs($rider)->postJson('/api/employee/orders/'.$job->id, ['action' => 'dispatch', 'version' => 2, 'submission_key' => (string) Str::uuid()])->assertOk();
        $this->assertSame($rider->id, $job->fresh()->rider_id);
        $this->actingAs($otherRider)->postJson('/api/employee/orders/'.$job->id, ['action' => 'dispatch', 'version' => 3, 'submission_key' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_the_owner_can_work_the_staff_workspace_and_is_recorded_as_the_actor(): void
    {
        $this->seed();
        $owner = User::where('role', 'super_admin')->firstOrFail();
        $manager = User::where('role', 'admin')->firstOrFail();
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $customer = Customer::whereNull('system_key')->firstOrFail();

        // A delivery handed over and waiting for a rider.
        $sale = Transaction::create([
            'receipt_number' => 'STAFF-RIDE',
            'customer_id' => $customer->id,
            'cashier_id' => $cashier->id,
            'order_type' => 'Delivery',
            'payment_method' => 'Account',
            'total_amount' => 100,
            'payment_status' => 'unpaid',
            'transaction_date' => today(),
            'transaction_time' => '09:00:00',
        ]);
        TransactionItem::create(['transaction_id' => $sale->id, 'product_id' => 'slim', 'item_name' => 'Refill', 'quantity' => 2, 'unit_price' => 50, 'line_total' => 100]);
        $ride = ProductionQueueItem::create([
            'transaction_id' => $sale->id,
            'receipt_number' => 'STAFF-RIDE',
            'customer_name' => $customer->name,
            'items_description' => '2 refills',
            'order_type' => 'Delivery',
        ]);
        $ride->forceFill(['fulfillment_status' => 'ready', 'handed_over_at' => now(), 'delivery_code' => '13572468'])->save();

        // A walk-in order still being prepared.
        $walkIn = ProductionQueueItem::create(['receipt_number' => 'STAFF-PREP', 'customer_name' => $customer->name, 'items_description' => '1 refill', 'order_type' => 'Walk-in']);
        $walkIn->forceFill(['fulfillment_status' => 'preparing'])->save();

        // The page is a management screen, and it offers both staff roles.
        $this->actingAs($owner)->get('/admin/staff-workspaces')
            ->assertOk()
            ->assertSee('Staff workspaces', false)
            ->assertSee('mode=refiller', false)
            ->assertSee('mode=rider', false);
        $this->actingAs($manager)->get('/admin/staff-workspaces')->assertOk();
        $this->actingAs($cashier)->get('/admin/staff-workspaces')->assertRedirect('/cashier');

        // Rider mode: the owner sees the shared delivery queue and can dispatch it.
        $orders = $this->actingAs($owner)->getJson('/api/employee/orders?mode=rider')->assertOk()->json('orders');
        $this->assertSame(['STAFF-RIDE'], array_column($orders, 'receipt'));
        $this->actingAs($owner)->postJson('/api/employee/orders/'.$ride->id, [
            'action' => 'dispatch',
            'version' => 0,
            'submission_key' => (string) Str::uuid(),
            'mode' => 'rider',
        ])->assertOk()->assertJsonPath('order.status', 'out_for_delivery');

        // The order is handled by the OWNER, not by a rider, and the role they
        // acted under is stamped beside the actor.
        $this->assertSame($owner->id, $ride->fresh()->rider_id);
        $history = $ride->fresh()->stage_history;
        $this->assertSame($owner->id, $history[0]['user_id']);
        $this->assertSame('rider', $history[0]['acting_role']);

        // Refiller mode: the owner sees the preparation queue and can mark it ready.
        $orders = $this->actingAs($owner)->getJson('/api/employee/orders?mode=refiller')->assertOk()->json('orders');
        $this->assertSame(['STAFF-PREP'], array_column($orders, 'receipt'));
        $this->actingAs($owner)->postJson('/api/employee/orders/'.$walkIn->id, [
            'action' => 'ready',
            'version' => 0,
            'submission_key' => (string) Str::uuid(),
            'mode' => 'refiller',
        ])->assertOk()->assertJsonPath('order.status', 'ready');
        $this->assertSame('refiller', $walkIn->fresh()->stage_history[0]['acting_role']);

        // A staff account cannot borrow another role: the mode is ignored.
        $rider = User::where('role', 'rider')->firstOrFail();
        $riderOrders = $this->actingAs($rider)->getJson('/api/employee/orders?mode=refiller')->assertOk()->json('orders');
        $this->assertNotContains('STAFF-PREP', array_column($riderOrders, 'receipt'));
        $this->actingAs($rider)->postJson('/api/employee/orders/'.$walkIn->id, [
            'action' => 'ready',
            'version' => 1,
            'submission_key' => (string) Str::uuid(),
            'mode' => 'refiller',
        ])->assertForbidden();
    }

    public function test_employee_activity_history_lists_only_todays_own_actions(): void
    {
        $this->seed();
        $refiller = User::where('role', 'refiller')->firstOrFail();
        $rider = User::where('role', 'rider')->firstOrFail();
        $job = ProductionQueueItem::create(['receipt_number' => 'HISTORY-QA', 'customer_name' => 'Customer', 'items_description' => '1 refill', 'order_type' => 'Walk-in']);

        $this->actingAs($refiller)
            ->postJson('/api/employee/orders/'.$job->id, ['action' => 'ready', 'version' => 0, 'submission_key' => (string) Str::uuid()])
            ->assertOk();

        $entries = $this->actingAs($refiller)->getJson('/api/activity')->assertOk()->json('entries');
        $this->assertCount(1, $entries);
        $this->assertSame('HISTORY-QA', $entries[0]['title']);
        $this->assertSame('Marked ready', $entries[0]['badge']);
        $this->assertSame('Customer', $entries[0]['subtitle']);

        // Another employee only sees their own work.
        $this->assertCount(0, $this->actingAs($rider)->getJson('/api/activity')->assertOk()->json('entries'));

        // Yesterday's action is history, but it is not today's activity.
        $stale = ProductionQueueItem::create(['receipt_number' => 'HISTORY-OLD', 'customer_name' => 'Customer', 'items_description' => '1 refill', 'order_type' => 'Walk-in']);
        $stale->forceFill([
            'stage_history' => [[
                'status' => 'ready',
                'action' => 'ready',
                'user_id' => $refiller->id,
                'at' => now()->subDay()->toISOString(),
                'reason' => null,
            ]],
        ])->save();
        $entries = $this->actingAs($refiller)->getJson('/api/activity')->assertOk()->json('entries');
        $this->assertCount(1, $entries);
        $this->assertSame('HISTORY-QA', $entries[0]['title']);
    }

    public function test_activity_today_uses_the_station_day_not_utc(): void
    {
        $this->seed();
        $refiller = User::where('role', 'refiller')->firstOrFail();
        $job = ProductionQueueItem::create(['receipt_number' => 'DAY-BOUNDARY', 'customer_name' => 'Customer', 'items_description' => '1 refill', 'order_type' => 'Walk-in']);

        // 11:50 PM last night is still yesterday, even though the stored
        // timestamp is UTC and therefore a different calendar day.
        $job->forceFill([
            'stage_history' => [[
                'status' => 'ready',
                'action' => 'ready',
                'user_id' => $refiller->id,
                'at' => now()->subDay()->setTime(23, 50)->toISOString(),
                'reason' => null,
            ]],
        ])->save();
        $this->assertCount(0, $this->actingAs($refiller)->getJson('/api/activity')->assertOk()->json('entries'));

        // Half past midnight tonight is today.
        $job->forceFill([
            'stage_history' => [[
                'status' => 'ready',
                'action' => 'ready',
                'user_id' => $refiller->id,
                'at' => now()->setTime(0, 30)->toISOString(),
                'reason' => null,
            ]],
        ])->save();
        $entries = $this->actingAs($refiller)->getJson('/api/activity')->assertOk()->json('entries');
        $this->assertCount(1, $entries);
        $this->assertSame('DAY-BOUNDARY', $entries[0]['title']);
    }

    public function test_a_damage_report_appears_in_the_reporter_activity(): void
    {
        $this->seed();
        $refiller = User::where('role', 'refiller')->firstOrFail();
        $item = \App\Models\InventoryItem::query()->current()->firstOrFail();

        $this->actingAs($refiller)
            ->postJson('/api/defects', ['inventory_item_id' => $item->id, 'quantity' => 2, 'reason' => 'Cracked cap'])
            ->assertCreated();

        $entries = $this->actingAs($refiller)->getJson('/api/activity')->assertOk()->json('entries');
        $this->assertCount(1, $entries);
        $this->assertSame('damage', $entries[0]['kind']);
        $this->assertSame('Reported damaged stock', $entries[0]['badge']);
        $this->assertSame('Cracked cap', $entries[0]['lines']);
        $this->assertSame('Waiting for a manager', $entries[0]['note']);

        // The report is evidence, so the reporter keeps seeing it.
        $this->assertDatabaseHas('defect_reports', ['reported_by' => $refiller->id, 'status' => 'pending']);
    }

    public function test_the_cashier_activity_shows_only_their_own_day(): void
    {
        $this->seed();
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $otherCashier = User::create(['username' => 'qa_second_cashier', 'name' => 'Second Cashier', 'password' => 'Cashier123', 'role' => 'cashier', 'is_active' => true]);
        $customer = Customer::whereNull('system_key')->firstOrFail();

        $sale = Transaction::create([
            'receipt_number' => 'ACTIVITY-SALE',
            'customer_id' => $customer->id,
            'cashier_id' => $cashier->id,
            'order_type' => 'Walk-in',
            'payment_method' => 'Cash',
            'total_amount' => 150,
            'transaction_date' => today(),
            'transaction_time' => '09:30:00',
        ]);

        $entries = $this->actingAs($cashier)->getJson('/api/activity')->assertOk()->json('entries');
        $this->assertNotEmpty($entries);
        $saleEntry = collect($entries)->firstWhere('kind', 'sale');
        $this->assertNotNull($saleEntry);
        $this->assertSame('ACTIVITY-SALE', $saleEntry['title']);
        $this->assertEquals(150, $saleEntry['amount']);

        // A colleague's sale is not part of this cashier's day.
        Transaction::create([
            'receipt_number' => 'ACTIVITY-OTHER',
            'customer_id' => $customer->id,
            'cashier_id' => $otherCashier->id,
            'order_type' => 'Walk-in',
            'payment_method' => 'Cash',
            'total_amount' => 90,
            'transaction_date' => today(),
            'transaction_time' => '09:45:00',
        ]);
        $titles = collect($this->actingAs($cashier)->getJson('/api/activity')->assertOk()->json('entries'))->pluck('title');
        $this->assertNotContains('ACTIVITY-OTHER', $titles->all());
    }

    public function test_employee_catalogue_endpoint_exposes_only_identity_and_counts(): void
    {
        $this->seed();
        $refiller = User::where('role', 'refiller')->firstOrFail();

        $items = $this->actingAs($refiller)->getJson('/api/employee/items')->assertOk()->json('items');
        $this->assertNotEmpty($items);
        $this->assertArrayHasKey('item', $items[0]);
        $this->assertArrayHasKey('on', $items[0]);
        $this->assertArrayNotHasKey('lead_time_days', $items[0]);
        $this->assertArrayNotHasKey('supplier_id', $items[0]);

        // The full stock screen stays out of reach for staff.
        $this->actingAs($refiller)->getJson('/api/inventory')->assertForbidden();
    }

    public function test_delivery_can_be_marked_ready_even_if_no_rider_is_active(): void
    {
        $this->seed();
        $rider = User::where('role', 'rider')->firstOrFail();
        $rider->employee->update(['is_active' => false]);
        $job = ProductionQueueItem::create(['receipt_number' => 'NO-RIDER', 'customer_name' => 'Customer', 'items_description' => '1 refill', 'order_type' => 'Delivery']);

        $this->actingAs(User::where('role', 'refiller')->firstOrFail())
            ->postJson('/api/employee/orders/'.$job->id, ['action' => 'ready', 'version' => 0, 'submission_key' => (string) Str::uuid()])
            ->assertOk();
        $this->assertSame('ready', $job->fresh()->fulfillment_status);
        $this->assertNull($job->fresh()->rider_id);
    }

    public function test_rider_cannot_report_preparation_issues_or_act_on_walk_in_orders(): void
    {
        $this->seed();
        $rider = User::where('role', 'rider')->firstOrFail();
        $job = ProductionQueueItem::create(['receipt_number' => 'ISSUE-QA', 'customer_name' => 'Customer', 'items_description' => '1 refill', 'order_type' => 'Delivery']);
        $job->forceFill(['rider_id' => $rider->id])->save();
        $this->actingAs($rider)->postJson('/api/employee/orders/'.$job->id, ['action' => 'issue', 'version' => 0, 'submission_key' => (string) Str::uuid(), 'reason' => 'Nobody home'])->assertUnprocessable();
        $job->forceFill(['order_type' => 'Walk-in', 'fulfillment_status' => 'ready'])->save();
        $this->postJson('/api/employee/orders/'.$job->id, ['action' => 'dispatch', 'version' => 0, 'submission_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('fulfillment_events', 0);
    }

    public function test_roles_and_inactive_staff_cannot_bypass_employee_permissions(): void
    {
        $this->seed();
        $refiller = User::where('role', 'refiller')->firstOrFail();
        $rider = User::where('role', 'rider')->firstOrFail();
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $owner = User::where('role', 'super_admin')->firstOrFail();
        $job = ProductionQueueItem::create(['receipt_number' => 'ACCESS-QA', 'customer_name' => 'Customer', 'items_description' => '1 refill', 'order_type' => 'Delivery']);
        $payload = ['action' => 'ready', 'version' => 0, 'submission_key' => (string) Str::uuid()];
        $this->actingAs($cashier)->postJson('/api/employee/orders/'.$job->id, $payload)->assertForbidden();
        $this->assertDatabaseCount('fulfillment_events', 0);
        $this->actingAs($owner)->postJson('/api/fulfillment/'.$job->id, $payload)->assertForbidden();
        $this->actingAs($refiller)->getJson('/api/transactions')->assertForbidden();
        $this->postJson('/api/queue/'.$job->id.'/deliver')->assertForbidden();
        $this->postJson('/api/employee/orders/'.$job->id, ['action' => 'assign', 'version' => 0, 'submission_key' => (string) Str::uuid(), 'rider_id' => $rider->id])->assertForbidden();
        $this->assertDatabaseCount('fulfillment_events', 0);
        $this->actingAs($owner)->postJson('/api/fulfillment/'.$job->id, ['action' => 'assign', 'version' => 0, 'submission_key' => (string) Str::uuid(), 'rider_id' => $rider->id])->assertForbidden();
        $this->assertNull($job->fresh()->rider_id);
        $rider->employee->update(['is_active' => false]);
        $this->actingAs($rider)->getJson('/api/employee/orders')->assertForbidden();
        $refiller->update(['is_active' => false]);
        $this->actingAs($refiller)->getJson('/api/employee/orders')->assertUnauthorized();
        $this->assertDatabaseCount('fulfillment_events', 0);
    }

    // --- From DeliveryAccountabilityTest.php ---

    

    private function action(string $action, array $extra = []): array
    {
        return $extra + ['action' => $action, 'version' => $this->job->fresh()->version, 'submission_key' => (string) Str::uuid()];
    }

    private function handoverAndDispatch(): void
    {
        $this->actingAs($this->cashier)->postJson('/api/fulfillment/'.$this->job->id, $this->action('handover', ['handover_quantity' => 2]))->assertOk();
        $this->actingAs($this->rider)->postJson('/api/employee/orders/'.$this->job->id, $this->action('dispatch'))->assertOk();
    }

    private function evidence(array $extra = []): array
    {
        return $extra + $this->action('deliver', ['photo' => UploadedFile::fake()->createWithContent('delivery.png', file_get_contents(base_path('tests/Fixtures/delivery.png'))),
            'recipient_name' => 'Buyer Recipient', 'recipient_code' => $this->job->fresh()->delivery_code,
            'received_quantity' => 2, 'reported_cash' => 100]);
    }

    private function deliver(array $extra = [])
    {
        return $this->actingAs($this->rider)->post('/api/employee/orders/'.$this->job->id, $this->evidence($extra), ['Accept' => 'application/json']);
    }

    public function test_cashier_handover_is_required_and_code_is_hidden_from_rider(): void
    {
        $this->actingAs($this->rider)->postJson('/api/employee/orders/'.$this->job->id, $this->action('dispatch'))->assertUnprocessable();
        $this->actingAs($this->cashier)->postJson('/api/fulfillment/'.$this->job->id, $this->action('handover', ['handover_quantity' => 1]))->assertUnprocessable();
        $this->handoverAndDispatch();
        $code = $this->job->fresh()->delivery_code;
        $this->assertMatchesRegularExpression('/^[0-9]{8}$/', $code);
        $this->assertNotSame($code, $this->job->fresh()->getRawOriginal('delivery_code'));
        $this->getJson('/api/employee/orders')->assertOk()->assertJsonPath('orders.0.confirmation_code', null)->assertDontSee($code);
        $this->actingAs($this->cashier)->getJson('/api/fulfillment')->assertOk()->assertJsonFragment(['confirmation_code' => $code]);
    }

    public function test_delivery_requires_valid_photo_buyer_code_quantity_and_cash_declaration(): void
    {
        $this->handoverAndDispatch();
        $this->actingAs($this->rider)->postJson('/api/employee/orders/'.$this->job->id, $this->action('deliver'))->assertUnprocessable()->assertJsonValidationErrors(['photo', 'recipient_name', 'recipient_code', 'received_quantity', 'reported_cash']);
        $this->deliver(['photo' => UploadedFile::fake()->createWithContent('fake.jpg', 'not an image')])->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->deliver(['recipient_code' => '00000000'])->assertUnprocessable();
        $this->deliver(['received_quantity' => 1])->assertUnprocessable();
        $this->deliver(['reported_cash' => 101])->assertUnprocessable();
        $this->deliver(['reported_cash' => 50])->assertUnprocessable()->assertJsonValidationErrors('collection_note');
        $this->assertDatabaseCount('delivery_proofs', 0);
        $this->assertFalse($this->job->fresh()->is_completed);
        $this->assertEquals(100, $this->buyer->fresh()->debt_balance);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_delivery_and_cash_report_do_not_settle_debt_until_cashier_accepts_remittance(): void
    {
        $this->handoverAndDispatch();
        $payload = $this->evidence();
        $this->actingAs($this->rider)->post('/api/employee/orders/'.$this->job->id, $payload, ['Accept' => 'application/json'])->assertOk();
        $this->post('/api/employee/orders/'.$this->job->id, $payload, ['Accept' => 'application/json'])->assertOk();
        $this->assertDatabaseCount('delivery_proofs', 1);
        $this->assertEquals(100, $this->buyer->fresh()->debt_balance);
        $this->assertDatabaseCount('transactions', 1);
        Storage::disk('local')->assertExists(DeliveryProof::firstOrFail()->photo_path);
        $this->actingAs($this->cashier);
        // A counter collection now waits for a manager, and the pending rider
        // cash still blocks it either way.
        $this->postJson('/api/customers/'.$this->buyer->id.'/settle', ['amount' => 100, 'submission_key' => (string) Str::uuid()])->assertUnprocessable();
        // Accepting the rider's cash at the counter stays a direct ledger write:
        // the delivery evidence is what authorises it, not a second approval.
        $remit = ['amount' => 100, 'submission_key' => (string) Str::uuid()];
        $response = $this->postJson('/api/deliveries/'.$this->job->id.'/remit', $remit)->assertOk();
        $this->postJson('/api/deliveries/'.$this->job->id.'/remit', $remit)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('transaction.no', $response->json('transaction.no'));
        $this->assertEquals(0, $this->buyer->fresh()->debt_balance);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('delivery_remittances', 1);
        $this->assertEquals('paid', $this->job->transaction->fresh()->payment_status);
        $this->postJson('/api/deliveries/'.$this->job->id.'/remit', ['amount' => 100, 'submission_key' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_short_remittance_requires_explanation_and_keeps_the_remainder_due(): void
    {
        $this->handoverAndDispatch();
        $this->deliver()->assertOk();
        $this->actingAs($this->cashier);
        $this->postJson('/api/deliveries/'.$this->job->id.'/remit', ['amount' => 60, 'submission_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson('/api/deliveries/'.$this->job->id.'/remit', ['amount' => 60, 'submission_key' => (string) Str::uuid(), 'note' => 'Rider is short forty pesos'])->assertOk();
        $this->assertEquals(40, $this->buyer->fresh()->debt_balance);
        $this->travel(10)->days();
        $this->getJson('/api/fulfillment')->assertOk()->assertJsonFragment(['pending_remittance' => 40]);
        $this->actingAs($this->rider)->getJson('/api/employee/orders')->assertOk()->assertJsonFragment(['pending_remittance' => 40]);
        $this->actingAs($this->cashier);
        $this->postJson('/api/deliveries/'.$this->job->id.'/remit', ['amount' => 40, 'submission_key' => (string) Str::uuid()])->assertOk();
        $this->assertEquals(0, $this->buyer->fresh()->debt_balance);
    }

    public function test_proofs_are_private_and_riders_cannot_accept_their_own_remittance(): void
    {
        $this->handoverAndDispatch();
        $this->deliver()->assertOk();
        $this->get('/api/employee/orders/'.$this->job->id.'/proof')->assertNotFound();
        $this->postJson('/api/deliveries/'.$this->job->id.'/remit', ['amount' => 100, 'submission_key' => (string) Str::uuid()])->assertForbidden();
        $refiller = User::where('role', 'refiller')->firstOrFail();
        $this->actingAs($refiller)->getJson('/api/employee/orders/'.$this->job->id.'/proof')->assertNotFound();
        $employee = Employee::create(['name' => 'Other Rider', 'job_title' => 'Rider', 'is_active' => true]);
        $other = User::create(['username' => 'other_rider', 'name' => 'Other', 'role' => 'rider', 'password' => 'Other123', 'employee_id' => $employee->id, 'is_active' => true]);
        $this->actingAs($other)->getJson('/api/employee/orders/'.$this->job->id.'/proof')->assertNotFound();
        $this->actingAs($this->cashier)->get('/api/deliveries/'.$this->job->id.'/proof')->assertOk();
        $this->assertEquals(100, $this->buyer->fresh()->debt_balance);
    }

    public function test_remittance_needs_no_drawer_and_conflicting_retries_are_rejected(): void
    {
        $this->handoverAndDispatch();
        $this->deliver()->assertOk();
        $this->actingAs($this->cashier);
        $body = ['amount' => 100, 'submission_key' => (string) Str::uuid()];
        $this->postJson('/api/deliveries/'.$this->job->id.'/remit', $body)->assertOk();
        $this->postJson('/api/deliveries/'.$this->job->id.'/remit', $body)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson('/api/deliveries/'.$this->job->id.'/remit', ['amount' => 99, 'submission_key' => $body['submission_key']])->assertConflict();
        $this->assertDatabaseCount('delivery_remittances', 1);
    }

    public function test_exact_photo_reuse_is_rejected_and_delivery_retry_cannot_change_cash(): void
    {
        $this->handoverAndDispatch();
        $payload = $this->evidence();
        $this->actingAs($this->rider)->post('/api/employee/orders/'.$this->job->id, $payload, ['Accept' => 'application/json'])->assertOk();
        $payload['reported_cash'] = 0;
        $this->post('/api/employee/orders/'.$this->job->id, $payload, ['Accept' => 'application/json'])->assertConflict();
        $this->buyer->update(['debt_balance' => 200]);
        $sale = $this->job->transaction->replicate();
        $sale->receipt_number = 'DELIVERY-SECOND';
        $sale->save();
        $line = $this->job->transaction->items->first()->replicate();
        $line->transaction_id = $sale->id;
        $line->save();
        $second = $this->job->fresh()->replicate();
        $second->forceFill(['transaction_id' => $sale->id, 'receipt_number' => $sale->receipt_number, 'fulfillment_status' => 'out_for_delivery', 'is_completed' => false, 'delivered_at' => null])->save();
        $this->job = $second;
        $this->deliver()->assertUnprocessable();
        $this->assertDatabaseCount('delivery_proofs', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertEquals(200, $this->buyer->fresh()->debt_balance);
    }

    public function test_partial_collection_keeps_uncollected_debt_and_replacement_codes_invalidate_old_ones(): void
    {
        $this->handoverAndDispatch();
        $oldCode = $this->job->fresh()->delivery_code;
        $this->actingAs($this->cashier)->postJson('/api/fulfillment/'.$this->job->id, $this->action('reset_code'))->assertOk();
        $this->deliver(['recipient_code' => $oldCode])->assertUnprocessable();
        $this->deliver(['reported_cash' => 60, 'collection_note' => 'Buyer paid sixty pesos only'])->assertOk();
        $this->actingAs($this->cashier);
        $this->postJson('/api/deliveries/'.$this->job->id.'/remit', ['amount' => 60, 'submission_key' => (string) Str::uuid()])->assertOk();
        $this->assertEquals(40, $this->buyer->fresh()->debt_balance);
        $this->assertEquals('unpaid', $this->job->transaction->fresh()->payment_status);
        $this->getJson('/api/fulfillment')->assertOk()->assertJsonFragment(['pending_remittance' => 0]);
    }

    public function test_history_remains_reviewable_and_referenced_staff_cannot_be_deleted(): void
    {
        $this->handoverAndDispatch();
        $this->deliver()->assertOk();
        $this->travel(10)->days();
        $owner = User::where('role', 'super_admin')->firstOrFail();
        $this->actingAs($owner)->getJson('/api/deliveries/'.$this->job->id)->assertOk()->assertJsonPath('order.proof.reported_cash', 100)->assertJsonCount(0, 'order.proof.remittances');
        $this->deleteJson('/api/users/'.$this->rider->id)->assertUnprocessable()->assertJsonValidationErrors('user');
        $this->deleteJson('/api/users/'.$this->cashier->id)->assertUnprocessable()->assertJsonValidationErrors('user');
        $this->assertDatabaseHas('users', ['id' => $this->rider->id]);
        $this->assertDatabaseCount('delivery_proofs', 1);
    }

    public function test_prepaid_deliveries_cannot_create_a_second_cash_collection(): void
    {
        $this->buyer->update(['debt_balance' => 0]);
        $this->job->transaction->update(['payment_method' => 'Cash', 'payment_status' => 'paid']);
        $this->handoverAndDispatch();
        $this->getJson('/api/employee/orders')->assertOk()->assertJsonFragment(['collection_limit' => 0]);
        $this->deliver(['reported_cash' => 1])->assertUnprocessable();
        $this->deliver(['reported_cash' => 0])->assertOk();
        $this->actingAs($this->cashier);
        $this->postJson('/api/deliveries/'.$this->job->id.'/remit', ['amount' => 1, 'submission_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('delivery_remittances', 0);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertEquals(0, $this->buyer->fresh()->debt_balance);
    }

    public function test_unreserved_debt_can_be_paid_without_consuming_pending_rider_cash(): void
    {
        $this->buyer->update(['debt_balance' => 150]);
        $this->handoverAndDispatch();
        $this->deliver()->assertOk();
        $this->actingAs($this->cashier);
        // The 50 that is not reserved for the rider is collectible, but a
        // cashier's collection waits for a manager: the balance holds until
        // approval and the cash is reserved meanwhile.
        $this->postJson('/api/customers/'.$this->buyer->id.'/settle', ['amount' => 50, 'submission_key' => (string) Str::uuid()])->assertStatus(202);
        $this->assertEquals(150, $this->buyer->fresh()->debt_balance);
        $this->assertEquals(50, $this->buyer->fresh()->settlement_pending_amount);
        // Nothing more is collectible while that collection awaits approval.
        $this->postJson('/api/customers/'.$this->buyer->id.'/settle', ['amount' => 1, 'submission_key' => (string) Str::uuid()])->assertUnprocessable();
        // Remitting the rider's cash settles its own 100.
        $this->postJson('/api/deliveries/'.$this->job->id.'/remit', ['amount' => 100, 'submission_key' => (string) Str::uuid()])->assertOk();
        $this->assertEquals(50, $this->buyer->fresh()->debt_balance);
    }

    public function test_two_cashiers_share_one_remittance_balance_and_get_only_the_cash_they_accept(): void
    {
        $this->handoverAndDispatch();
        $this->deliver()->assertOk();
        $this->actingAs($this->cashier);
        $this->postJson('/api/deliveries/'.$this->job->id.'/remit', ['amount' => 40, 'submission_key' => (string) Str::uuid(), 'note' => 'First cash handover of forty'])->assertOk();
        $owner = User::where('role', 'super_admin')->firstOrFail();
        $this->actingAs($owner);
        $this->postJson('/api/deliveries/'.$this->job->id.'/remit', ['amount' => 100, 'submission_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson('/api/deliveries/'.$this->job->id.'/remit', ['amount' => 60, 'submission_key' => (string) Str::uuid()])->assertOk();
        $this->actingAs($this->cashier);
        $this->assertEquals(0, $this->buyer->fresh()->debt_balance);
        $this->assertDatabaseCount('delivery_remittances', 2);
        $this->assertDatabaseCount('transactions', 3);
    }

    public function test_tiny_oversized_and_script_images_are_rejected_without_storing_files(): void
    {
        $this->handoverAndDispatch();
        $tiny = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aSuoAAAAASUVORK5CYII=');
        $this->deliver(['photo' => UploadedFile::fake()->createWithContent('tiny.png', $tiny)])->assertUnprocessable()->assertJsonValidationErrors('photo');
        $large = file_get_contents(base_path('tests/Fixtures/delivery.png')).str_repeat('x', 5 * 1024 * 1024);
        $this->deliver(['photo' => UploadedFile::fake()->createWithContent('large.png', $large)])->assertUnprocessable()->assertJsonValidationErrors('photo');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="240"><script>alert(1)</script></svg>';
        $this->deliver(['photo' => UploadedFile::fake()->createWithContent('script.svg', $svg)])->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->assertFalse($this->job->fresh()->is_completed);
        $this->assertDatabaseCount('delivery_proofs', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_negative_and_fractional_cent_cash_is_rejected(): void
    {
        $this->handoverAndDispatch();
        $this->deliver(['reported_cash' => -1])->assertUnprocessable()->assertJsonValidationErrors('reported_cash');
        $this->deliver(['reported_cash' => '1.001'])->assertUnprocessable()->assertJsonValidationErrors('reported_cash');
        $this->deliver()->assertOk();
        $this->actingAs($this->cashier);
        foreach ([0, -1, '1.001', 101] as $amount) {
            $this->postJson('/api/deliveries/'.$this->job->id.'/remit', ['amount' => $amount, 'submission_key' => (string) Str::uuid()])->assertUnprocessable();
        }
        $this->assertDatabaseCount('delivery_remittances', 0);
        $this->assertEquals(100, $this->buyer->fresh()->debt_balance);
    }

    public function test_a_rider_cannot_reset_the_buyer_code_or_confirm_cashier_handover(): void
    {
        $this->handoverAndDispatch();
        $code = $this->job->fresh()->delivery_code;
        $this->postJson('/api/employee/orders/'.$this->job->id, $this->action('reset_code'))->assertForbidden();
        $this->postJson('/api/employee/orders/'.$this->job->id, $this->action('handover', ['handover_quantity' => 2]))->assertForbidden();
        $this->assertSame($code, $this->job->fresh()->delivery_code);
        $this->rider->employee->update(['is_active' => false]);
        $this->deliver()->assertForbidden();
        $this->assertDatabaseCount('delivery_proofs', 0);
    }

    public function test_a_delivery_cannot_be_completed_before_dispatch(): void
    {
        $this->actingAs($this->cashier)->postJson('/api/fulfillment/'.$this->job->id, $this->action('handover', ['handover_quantity' => 2]))->assertOk();
        $this->deliver()->assertUnprocessable();
        $this->assertEquals('ready', $this->job->fresh()->fulfillment_status);
        $this->assertDatabaseCount('delivery_proofs', 0);
    }

    public function test_buyer_code_guessing_is_rate_limited(): void
    {
        $this->handoverAndDispatch();
        for ($attempt = 0; $attempt < 9; $attempt++) {
            $this->deliver(['recipient_code' => '00000000'])->assertUnprocessable();
        }
        $this->deliver(['recipient_code' => '00000000'])->assertStatus(429);
        $this->assertFalse($this->job->fresh()->is_completed);
        $this->assertDatabaseCount('delivery_proofs', 0);
    }

    public function test_a_failure_after_photo_upload_rolls_back_evidence_and_removes_the_file(): void
    {
        $this->handoverAndDispatch();
        DeliveryProof::creating(fn () => throw new \RuntimeException('Simulated database failure after photo storage'));
        try {
            $this->deliver()->assertStatus(500);
        } finally {
            DeliveryProof::flushEventListeners();
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('delivery_proofs', 0);
        $this->assertDatabaseCount('fulfillment_events', 2);
        $this->assertFalse($this->job->fresh()->is_completed);
        $this->assertEquals(100, $this->buyer->fresh()->debt_balance);
        $this->deliver()->assertOk();
    }

    // --- From RiderAssignmentTest.php ---
    public function test_manual_assignment_is_rejected_for_management_and_cashiers_without_changing_history(): void
    {
        $this->seed();
        $rider = User::where('role', 'rider')->firstOrFail();
        $job = ProductionQueueItem::create(['receipt_number' => 'FIFO-ONLY', 'customer_name' => 'Test', 'items_description' => '1 refill', 'order_type' => 'Delivery']);
        foreach (['super_admin', 'admin', 'cashier'] as $role) {
            $actor = User::where('role', $role)->firstOrFail();
            $payload = ['action' => 'assign', 'rider_id' => $rider->id, 'version' => 0, 'submission_key' => (string) Str::uuid()];
            $this->actingAs($actor)->postJson('/api/fulfillment/'.$job->id, $payload)->assertForbidden();
            $this->postJson('/api/fulfillment/'.$job->id, $payload)->assertForbidden();
        }
        $this->assertNull($job->fresh()->rider_id);
        $this->assertSame(0, $job->fresh()->version);
        $this->assertDatabaseCount('fulfillment_events', 0);
    }

    public function test_queue_sales_and_fulfillment_use_snapshot_product_names_without_rewriting_history(): void
    {
        $this->seed();
        $cashier = User::where('role', 'cashier')->firstOrFail();
        $sale = Transaction::create(['receipt_number' => 'FULL-NAME-QA', 'customer_id' => Customer::whereNull('system_key')->firstOrFail()->id,
            'cashier_id' => $cashier->id, 'order_type' => 'Delivery', 'payment_method' => 'Account', 'total_amount' => 500,
            'gallons_volume' => '0 gal', 'transaction_date' => today(), 'transaction_time' => '08:00:00']);
        TransactionItem::create(['transaction_id' => $sale->id, 'product_id' => 'newS', 'item_name' => 'New Slim 5-Gal Jug', 'quantity' => 2, 'unit_price' => 250, 'line_total' => 500]);
        $job = ProductionQueueItem::create(['transaction_id' => $sale->id, 'receipt_number' => $sale->receipt_number, 'customer_name' => 'Test', 'items_description' => '2S', 'order_type' => 'Delivery']);
        $description = '2 × New Slim 5-Gal Jug';
        $this->actingAs($cashier)->getJson('/api/transactions')->assertOk()->assertJsonFragment(['gal' => $description]);
        $this->getJson('/api/queue')->assertOk()->assertJsonFragment(['items' => $description]);
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonFragment(['gal' => $description])->assertJsonFragment(['items' => $description]);
        $this->getJson('/api/fulfillment')->assertOk()->assertJsonFragment(['description' => $description]);
        $this->assertSame('0 gal', $sale->fresh()->gallons_volume);
        $this->assertSame('2S', $job->fresh()->items_description);
    }

    // --- From EmployeeAccessTest.php ---
    public function test_employee_cannot_access_cashier_data_and_attendance_routes_are_gone(): void
    {
        $this->seed();
        $employee = Employee::where('name', 'Carla Mendoza')->firstOrFail();
        $user = User::create(['username' => 'qa_rider', 'name' => 'Rider', 'password' => 'Rider123', 'role' => 'rider', 'employee_id' => $employee->id, 'is_active' => true]);
        $this->actingAs($user);
        foreach (['bootstrap', 'customers', 'transactions', 'inventory', 'users'] as $path) {
            $this->getJson('/api/'.$path)->assertForbidden();
        }
        $this->get('/cashier')->assertForbidden();
        $this->get('/employee')->assertOk();

        // Attendance left the product, so the routes must not answer at all.
        $this->getJson('/api/attendance')->assertNotFound();
        $this->getJson('/api/employee/attendance')->assertNotFound();
        $this->postJson('/api/employee/attendance/time-in')->assertNotFound();
        $this->postJson('/api/attendance/employees/'.$employee->id.'/time-in')->assertNotFound();
        $this->get('/cashier/attendance')->assertNotFound();
    }

    // --- From EmployeeAndDeliveryTest.php ---
    public function test_owner_can_manage_employees_without_creating_login_accounts(): void
    {
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $users = User::count();
        $employees = Employee::count();
        $id = $this->postJson('/api/employees', ['name' => 'Ana Santos', 'job_title' => 'Refill operator', 'contact_number' => '09123456789', 'is_active' => true])->assertCreated()->json('employee.id');
        $this->patchJson('/api/employees/'.$id, ['name' => 'Ana Santos', 'job_title' => 'Delivery worker', 'is_active' => false])->assertOk()->assertJsonPath('employee.is_active', false);
        $this->getJson('/api/employees')->assertOk()->assertJsonCount($employees + 1, 'employees');
        $this->assertSame($users, User::count());
        $this->deleteJson('/api/employees/'.$id)->assertOk();
        $this->assertSoftDeleted('employees', ['id' => $id]);
        $this->getJson('/api/employees')->assertJsonCount($employees, 'employees');
    }

    public function test_employee_records_have_no_screen_of_their_own(): void
    {
        // Staff are managed on the single Team & Access tab; the employee
        // directory survives only to attribute delivery work in history.
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $this->get('/admin/employees')->assertNotFound();
        $this->get('/admin/users')->assertOk()->assertSee('Team & Access')->assertDontSee('Job title');
    }

    public function test_employee_validation_and_owner_permissions(): void
    {
        $this->actingAs(User::where('username', 'cashier')->firstOrFail());
        $this->getJson('/api/employees')->assertForbidden();
        $this->postJson('/api/employees', ['name' => 'Ana', 'job_title' => 'Worker'])->assertForbidden();
        $this->get('/admin/employees')->assertNotFound();
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $this->postJson('/api/employees', ['name' => '', 'job_title' => ''])->assertUnprocessable()->assertJsonValidationErrors(['name', 'job_title']);
    }

    public function test_cashier_can_handover_orders_and_retries_are_idempotent(): void
    {
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $this->actingAs($cashier);
        $item = ProductionQueueItem::create(['receipt_number' => 'TEST-DELIVERY', 'customer_name' => 'Ana', 'items_description' => '2 refills', 'order_type' => 'Walk-in', 'stage' => 0, 'is_completed' => false]);
        $item->forceFill(['fulfillment_status' => 'ready'])->save();
        $this->postJson('/api/queue/'.$item->id.'/deliver')->assertOk()->assertJsonPath('item.status', 'delivered');
        $this->postJson('/api/queue/'.$item->id.'/advance')->assertForbidden();
        $item->refresh();
        $this->assertTrue($item->is_completed);
        $this->assertNotNull($item->delivered_at);
        $this->assertSame($cashier->id, $item->stage_history[0]['user_id']);
        $time = $item->delivered_at->toISOString();
        $this->postJson('/api/queue/'.$item->id.'/deliver')->assertOk();
        $item->refresh();
        $this->assertCount(1, $item->stage_history);
        $this->assertSame($time, $item->delivered_at->toISOString());
        $this->getJson('/api/queue?status=active&search=TEST-DELIVERY')->assertJsonCount(0, 'queue');
        $this->getJson('/api/queue?status=delivered&search=TEST-DELIVERY')->assertOk()->assertJsonCount(1, 'queue');
        $this->getJson('/api/queue?status=invalid')->assertUnprocessable();
    }

    // --- From EmployeeSeederTest.php ---
    public function test_sample_employees_are_repeatable_without_overwriting_owner_changes(): void
    {
        $this->seed(EmployeeSeeder::class);
        $this->assertDatabaseCount('employees', 3);
        $this->assertSame(3, Employee::where('is_active', true)->count());
        $this->assertDatabaseCount('users', 0);

        $employee = Employee::where('name', 'Marco Reyes')->firstOrFail();
        $employee->update(['job_title' => 'Senior refill operator', 'is_active' => false]);
        Employee::where('name', 'Luis Garcia')->firstOrFail()->delete();
        $this->seed(EmployeeSeeder::class);

        $this->assertDatabaseCount('employees', 3);
        $this->assertSame('Senior refill operator', $employee->fresh()->job_title);
        $this->assertFalse($employee->fresh()->is_active);
        $this->assertSoftDeleted('employees', ['name' => 'Luis Garcia']);
    }

    // --- From RoleWorkspaceTest.php ---
    private function loginAsRole(string $role): void
    {
        $this->actingAs(User::create(['name' => 'Test User', 'username' => 'workspace_'.$role,
            'email' => $role.'@example.com', 'password' => 'Private123!', 'role' => $role, 'is_active' => true]));
    }

    public function test_owner_has_business_controls_and_cashier_return_link(): void
    {
        $this->loginAsRole('super_admin');
        $this->get('/admin/dashboard')->assertOk()->assertSee('Overview')->assertSee('Business controls');
        $this->get('/cashier/stocks')->assertOk()->assertSee('Owner Portal');
    }

    public function test_manager_is_not_shown_owner_setup_links(): void
    {
        $this->loginAsRole('admin');
        $this->get('/admin/dashboard')->assertOk()->assertDontSee('href="http://localhost/admin/settings"', false)
            ->assertDontSee('id="stepSettings"', false)->assertDontSee('Business controls');
        $this->get('/cashier/stocks')->assertOk()->assertSee('Manager Portal');
    }

    public function test_cashier_stock_page_is_read_only_and_has_no_planning_columns(): void
    {
        $this->loginAsRole('cashier');
        $this->get('/cashier/stocks')->assertOk()->assertSee('Stock Availability')
            ->assertDontSee('Safety Stock')->assertDontSee('Reorder Point')->assertDontSee('Owner Portal');
        $this->postJson('/api/inventory', [])->assertForbidden();
        $this->putJson('/api/settings', [])->assertForbidden();
    }

    public function test_manager_catalog_creates_are_queued_for_owner_approval(): void
    {
        $this->loginAsRole('admin');
        // An empty payload is still rejected by validation, not by rank.
        $this->postJson('/api/products', [])->assertUnprocessable();
        $this->assertDatabaseCount('products', 0);

        // A valid manager create becomes a pending request - not a live row.
        $this->postJson('/api/products', [
            'name' => 'Manager Item', 'price' => 50,
            'category' => 'refill', 'container_kind' => 'S',
        ])->assertStatus(202)->assertJsonPath('pending_approval', true);

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseHas('approval_requests', ['type' => 'product.create', 'state' => 'pending']);
    }

    public function test_cashier_history_is_scoped_in_both_list_and_bootstrap(): void
    {
        $this->seed();
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $owner = User::where('username', 'admin')->firstOrFail();
        $customer = \App\Models\Customer::firstOrCreate(['name' => 'History customer'], ['customer_type' => 'regular']);
        foreach ([$cashier, $owner] as $actor) {
            \App\Models\Transaction::create([
                'receipt_number' => 'ROLE-'.$actor->id, 'customer_id' => $customer->id,
                'cashier_id' => $actor->id, 'order_type' => 'Walk-in', 'payment_method' => 'Cash',
                'total_amount' => 50, 'transaction_date' => today(), 'transaction_time' => '10:00:00',
            ]);
        }
        $this->actingAs($cashier);
        foreach (['/api/transactions?paginated=1', '/api/bootstrap'] as $url) {
            $this->getJson($url)->assertOk()->assertJsonCount(1, 'transactions')
                ->assertDontSee('ROLE-'.$owner->id);
        }
        $this->getJson('/api/bootstrap')->assertJsonMissingPath('meta.sales_today_total');
        $this->actingAs($owner)->getJson('/api/transactions')->assertOk()->assertJsonCount(2, 'transactions');
    }

    public function test_catalog_writes_are_owner_only_and_manager_can_read_prices(): void
    {
        $this->seed();
        $product = \App\Models\Product::firstOrFail();
        $price = $product->price;
        $this->loginAsRole('admin');
        $this->get('/admin/inventory')->assertOk()->assertDontSee('id="tillFormPanel"', false);
        $this->get('/admin/orders')->assertOk()->assertSee('fulfillmentRows');
        $this->getJson('/api/products')->assertOk();

        // A manager's write is a proposal: queued, and the price does not move.
        $this->patchJson('/api/products/'.$product->id, ['price' => 123])
            ->assertStatus(202)->assertJsonPath('pending_approval', true);
        $this->deleteJson('/api/products/'.$product->id)->assertStatus(202);
        $this->putJson('/api/products/'.$product->id.'/recipe', [
            'consumables' => [['inventory_item_id' => \App\Models\InventoryItem::value('id'), 'qty_per_sale' => 1]],
        ])->assertStatus(202);
        $this->assertEquals($price, $product->fresh()->price);
        $this->assertSame(3, \App\Models\ApprovalRequest::count());

        // The owner still writes directly, and the queued proposals stay idle.
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $this->patchJson('/api/products/'.$product->id, ['price' => 123])->assertOk();
        $this->assertEquals(123, $product->fresh()->price);
        $this->get('/admin/inventory')->assertOk()->assertSee('id="tillFormPanel"', false);
    }

    public function test_cashier_cannot_complete_preparation_or_rider_work(): void
    {
        $this->loginAsRole('cashier');
        foreach (['Walk-in', 'Delivery'] as $type) {
            $item = \App\Models\ProductionQueueItem::create([
                'receipt_number' => $type, 'customer_name' => 'Test',
                'items_description' => '1 refill', 'order_type' => $type,
                'stage' => 0, 'is_completed' => false,
            ]);
            $item->forceFill(['fulfillment_status' => $type === 'Delivery' ? 'ready' : 'preparing'])->save();
            $this->postJson('/api/queue/'.$item->id.'/deliver')->assertStatus(409);
            $this->assertFalse($item->fresh()->is_completed);
            $this->assertNull($item->fresh()->delivered_at);
        }
    }
}
