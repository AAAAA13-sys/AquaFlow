<?php

namespace Tests\Feature;

use App\Models\ProductionQueueItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RiderAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_assignment_is_audited_idempotent_and_cannot_be_changed_after_handover(): void
    {
        $this->seed();
        $owner = User::where('role', 'super_admin')->firstOrFail();
        $rider = User::where('role', 'rider')->firstOrFail();
        $job = ProductionQueueItem::create(['receipt_number' => 'ASSIGN-1', 'customer_name' => 'Test', 'items_description' => '1 refill', 'order_type' => 'Delivery']);
        $payload = ['action' => 'assign', 'rider_id' => $rider->id, 'version' => 0, 'submission_key' => (string) Str::uuid()];
        $this->actingAs(User::where('role', 'cashier')->firstOrFail())->postJson('/api/fulfillment/'.$job->id, $payload)->assertForbidden();
        $this->assertNull($job->fresh()->rider_id);
        $this->actingAs($owner)->postJson('/api/fulfillment/'.$job->id, $payload)->assertOk();
        $this->postJson('/api/fulfillment/'.$job->id, $payload)->assertOk();
        $this->assertEquals($rider->id, $job->fresh()->rider_id);
        $this->assertDatabaseCount('fulfillment_events', 1);
        $this->actingAs($rider)->getJson('/api/employee/orders')->assertOk()->assertSee('ASSIGN-1');
        $this->actingAs($owner);
        $job->refresh()->forceFill(['handed_over_at' => now()])->save();
        $payload['version'] = 1;
        $payload['submission_key'] = (string) Str::uuid();
        $this->postJson('/api/fulfillment/'.$job->id, $payload)->assertUnprocessable();
    }

    public function test_assignment_rejects_inactive_riders_without_mutating_the_order(): void
    {
        $this->seed();
        $rider = User::where('role', 'rider')->firstOrFail();
        $rider->update(['is_active' => false]);
        $job = ProductionQueueItem::create(['receipt_number' => 'ASSIGN-2', 'customer_name' => 'Test', 'items_description' => '1 refill', 'order_type' => 'Delivery']);
        $this->actingAs(User::where('role', 'super_admin')->firstOrFail())
            ->postJson('/api/fulfillment/'.$job->id, ['action' => 'assign', 'rider_id' => $rider->id, 'version' => 0, 'submission_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('rider_id');
        $this->assertNull($job->fresh()->rider_id);
        $this->assertDatabaseCount('fulfillment_events', 0);
    }

    public function test_manager_can_assign_but_stale_updates_and_walk_in_assignment_are_rejected(): void
    {
        $this->seed();
        $manager = User::create(['name' => 'Manager', 'username' => 'assignment_manager', 'email' => 'manager@example.com', 'password' => 'Private123!', 'role' => 'admin', 'is_active' => true]);
        $rider = User::where('role', 'rider')->firstOrFail();
        $job = ProductionQueueItem::create(['receipt_number' => 'ASSIGN-3', 'customer_name' => 'Test', 'items_description' => '1 refill', 'order_type' => 'Delivery']);
        $payload = ['action' => 'assign', 'rider_id' => $rider->id, 'version' => 0, 'submission_key' => (string) Str::uuid()];
        $this->actingAs($manager)->postJson('/api/fulfillment/'.$job->id, $payload)->assertOk();
        $payload['submission_key'] = (string) Str::uuid();
        $this->postJson('/api/fulfillment/'.$job->id, $payload)->assertConflict();
        $job->refresh()->update(['order_type' => 'Walk-in']);
        $payload['version'] = 1;
        $this->postJson('/api/fulfillment/'.$job->id, $payload)->assertUnprocessable();
        $this->assertDatabaseCount('fulfillment_events', 1);
    }
}
