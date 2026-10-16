<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\ProductionQueueItem;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FulfillmentTest extends TestCase
{
    use RefreshDatabase;

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
        $this->actingAs($owner)->postJson('/api/fulfillment/'.$job->id, $payload)->assertForbidden();
        $this->actingAs($refiller)->getJson('/api/transactions')->assertForbidden();
        $this->postJson('/api/queue/'.$job->id.'/deliver')->assertForbidden();
        $this->postJson('/api/employee/orders/'.$job->id, ['action' => 'assign', 'version' => 0, 'submission_key' => (string) Str::uuid(), 'rider_id' => $rider->id])->assertUnprocessable();
        $this->actingAs($owner)->postJson('/api/fulfillment/'.$job->id, ['action' => 'assign', 'version' => 0, 'submission_key' => (string) Str::uuid(), 'rider_id' => $rider->id])->assertUnprocessable();
        $rider->employee->update(['is_active' => false]);
        $this->actingAs($rider)->getJson('/api/employee/orders')->assertForbidden();
        $refiller->update(['is_active' => false]);
        $this->actingAs($refiller)->getJson('/api/employee/orders')->assertUnauthorized();
        $this->assertDatabaseCount('fulfillment_events', 0);
    }
}
