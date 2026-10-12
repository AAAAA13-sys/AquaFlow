<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ProductionQueueItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $customer = \App\Models\Customer::whereNull('system_key')->firstOrFail();
        $customer->update(['debt_balance' => 100]);
        $transaction = \App\Models\Transaction::create(['receipt_number' => 'QA-DELIVERY', 'customer_id' => $customer->id, 'cashier_id' => User::where('role', 'cashier')->firstOrFail()->id, 'order_type' => 'Delivery', 'payment_method' => 'Account', 'total_amount' => 100, 'transaction_date' => today(), 'transaction_time' => '08:00:00']);
        $job->forceFill(['transaction_id' => $transaction->id])->save();
        $stock = StockMovement::count();
        $this->actingAs($rider);
        $payload = ['action' => 'dispatch', 'version' => 0, 'submission_key' => (string) Str::uuid()];
        $this->postJson('/api/employee/orders/'.$job->id, $payload)->assertOk();
        $this->postJson('/api/employee/orders/'.$job->id, $payload)->assertOk();
        $this->assertSame(1, $job->fresh()->version);
        $this->postJson('/api/employee/orders/'.$job->id, ['action' => 'deliver', 'version' => 1, 'submission_key' => (string) Str::uuid()])->assertOk();
        $this->assertTrue($job->fresh()->is_completed);
        $this->assertEquals(100, $customer->fresh()->debt_balance);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertSame($stock, StockMovement::count());
        $this->assertDatabaseCount('fulfillment_events', 2);
        foreach (['admin', 'cashier'] as $role) {
            $this->actingAs(User::where('role', $role)->firstOrFail())
                ->getJson('/api/queue?status=delivered')->assertOk()
                ->assertJsonFragment(['id' => $job->id, 'completed' => true]);
        }
    }

    public function test_rider_cannot_skip_an_older_unready_order_or_access_another_riders_job(): void
    {
        $this->seed();
        $rider = User::create(['username' => 'qa_rider', 'name' => 'Rider', 'password' => 'Rider123', 'role' => 'rider', 'employee_id' => Employee::where('name', 'Carla Mendoza')->firstOrFail()->id, 'is_active' => true]);
        $older = ProductionQueueItem::create(['receipt_number' => 'FIFO-1', 'customer_name' => 'Customer', 'items_description' => '1 refill', 'order_type' => 'Delivery']);
        $newer = ProductionQueueItem::create(['receipt_number' => 'FIFO-2', 'customer_name' => 'Customer', 'items_description' => '1 refill', 'order_type' => 'Delivery']);
        $older->forceFill(['rider_id' => $rider->id])->save();
        $newer->forceFill(['rider_id' => $rider->id, 'fulfillment_status' => 'ready'])->save();
        $this->actingAs($rider)->postJson('/api/employee/orders/'.$newer->id, ['action' => 'dispatch', 'version' => 0, 'submission_key' => (string) Str::uuid()])->assertUnprocessable();
        $newer->forceFill(['rider_id' => null])->save();
        $this->getJson('/api/employee/orders')->assertOk()->assertJsonCount(1, 'orders')->assertDontSee('debt_balance')->assertDontSee('total_amount');
        $this->postJson('/api/employee/orders/'.$newer->id, ['action' => 'dispatch', 'version' => 0, 'submission_key' => (string) Str::uuid()])->assertForbidden();
        $this->postJson('/api/employee/orders/'.$older->id, ['action' => 'ready', 'version' => 0, 'submission_key' => (string) Str::uuid()])->assertForbidden();
        $this->assertDatabaseCount('fulfillment_events', 0);
    }
}
