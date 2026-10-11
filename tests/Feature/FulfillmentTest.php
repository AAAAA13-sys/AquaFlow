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
        $employee = Employee::firstOrFail();
        $rider = User::create(['username' => 'rider', 'name' => 'Rider', 'password' => 'Rider123', 'role' => 'rider', 'employee_id' => $employee->id, 'is_active' => true]);
        $job = ProductionQueueItem::where('is_completed', false)->firstOrFail();
        $job->forceFill(['order_type' => 'Delivery', 'fulfillment_status' => 'ready', 'rider_id' => $rider->id])->save();
        $stock = StockMovement::count();
        $this->actingAs($rider);
        $payload = ['action' => 'dispatch', 'version' => 0, 'submission_key' => (string) Str::uuid()];
        $this->postJson('/api/employee/orders/'.$job->id, $payload)->assertOk();
        $this->postJson('/api/employee/orders/'.$job->id, $payload)->assertOk();
        $this->assertSame(1, $job->fresh()->version);
        $this->postJson('/api/employee/orders/'.$job->id, ['action' => 'deliver', 'version' => 1, 'submission_key' => (string) Str::uuid()])->assertOk();
        $this->assertTrue($job->fresh()->is_completed);
        $this->assertSame($stock, StockMovement::count());
        $this->assertDatabaseCount('fulfillment_events', 2);
    }

    public function test_rider_cannot_skip_an_older_unready_order_or_access_another_riders_job(): void
    {
        $this->seed();
        $rider = User::create(['username' => 'rider', 'name' => 'Rider', 'password' => 'Rider123', 'role' => 'rider', 'employee_id' => Employee::firstOrFail()->id, 'is_active' => true]);
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
