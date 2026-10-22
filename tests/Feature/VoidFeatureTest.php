<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VoidFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_sale_void_requires_a_senior_password_and_is_idempotent(): void
    {
        $this->seed();
        $stockBefore = \App\Models\InventoryItem::pluck('stock_on_hand', 'id')->all();
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $manager = User::create(['username' => 'void-manager', 'name' => 'Void Manager', 'role' => 'admin', 'is_active' => true, 'password' => 'Manager123!']);
        $sale = $this->actingAs($cashier)->postJson('/api/transactions', [
            'submission_key' => (string) Str::uuid(),
            'customer_id' => Customer::where('name', 'Walk-in Guest')->value('id'),
            'order_type' => 'Walk-in', 'cash_tendered' => 100,
            'items' => [['product_id' => 'slim', 'quantity' => 2]],
        ])->assertCreated()->json('transaction');
        $body = ['submission_key' => (string) Str::uuid(), 'transaction_id' => $sale['id'],
            'reason' => 'Duplicate order entered', 'approver_username' => $manager->username,
            'approver_password' => 'wrong'];
        $this->postJson('/api/voids', $body)->assertUnprocessable();
        $body['approver_password'] = 'Manager123!';
        $this->postJson('/api/voids', $body)->assertCreated()->assertJsonPath('replayed', false);
        $this->postJson('/api/voids', $body)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame($stockBefore, \App\Models\InventoryItem::pluck('stock_on_hand', 'id')->all());
        $this->assertDatabaseCount('void_records', 1);
        $this->assertDatabaseHas('production_queue', ['transaction_id' => $sale['id'], 'fulfillment_status' => 'voided']);
        $this->getJson('/api/transactions')->assertOk()->assertJsonFragment(['id' => $sale['id'], 'voided' => true]);
    }

    public function test_managers_cannot_approve_peers_and_staff_cannot_approve_voids(): void
    {
        $this->seed();
        $manager = User::where('username', 'manager')->firstOrFail();
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $peer = User::create(['username' => 'peer', 'name' => 'Peer', 'role' => 'admin', 'is_active' => true, 'password' => 'Peer123!']);
        $owner = User::create(['username' => 'void-owner', 'name' => 'Owner', 'role' => 'super_admin', 'is_active' => true, 'password' => 'Owner123!']);
        $body = ['submission_key' => (string) Str::uuid(), 'reason' => 'Wrong unpaid order',
            'cart' => [['product_id' => 'slim', 'quantity' => 1]], 'approver_username' => $peer->username, 'approver_password' => 'Peer123!'];
        $this->actingAs($manager)->postJson('/api/voids', $body)->assertForbidden();
        $body['approver_username'] = $owner->username;
        $body['approver_password'] = 'Owner123!';
        $this->postJson('/api/voids', $body)->assertCreated();
        $body['submission_key'] = (string) Str::uuid();
        $body['approver_username'] = $cashier->username;
        $body['approver_password'] = '1234';
        $this->actingAs($cashier)->postJson('/api/voids', $body)->assertForbidden();
    }

    public function test_account_void_reverses_debt_and_prepared_sales_are_rejected(): void
    {
        $this->seed();
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $manager = User::create(['username' => 'void-manager', 'name' => 'Manager', 'role' => 'admin', 'is_active' => true, 'password' => 'Manager123!']);
        $customer = Customer::where('name', 'Santos Family')->firstOrFail();
        $before = $customer->debt_balance;
        $sale = $this->actingAs($cashier)->postJson('/api/transactions', [
            'submission_key' => (string) Str::uuid(), 'customer_id' => $customer->id,
            'order_type' => 'Delivery', 'delivery_address' => 'Test delivery address',
            'items' => [['product_id' => 'slim', 'quantity' => 2]],
        ])->assertCreated()->json('transaction');
        $body = ['submission_key' => (string) Str::uuid(), 'transaction_id' => $sale['id'],
            'reason' => 'Duplicate delivery order', 'approver_username' => $manager->username, 'approver_password' => 'Manager123!'];
        $job = \App\Models\ProductionQueueItem::where('transaction_id', $sale['id'])->firstOrFail();
        $job->forceFill(['fulfillment_status' => 'ready'])->save();
        $this->postJson('/api/voids', $body)->assertUnprocessable();
        $this->assertEquals($before + $sale['total'], $customer->refresh()->debt_balance);
        $job->forceFill(['fulfillment_status' => 'preparing'])->save();
        $this->postJson('/api/voids', $body)->assertCreated();
        $this->assertEquals($before, $customer->refresh()->debt_balance);
    }

    public function test_incorrect_approval_credentials_are_rate_limited(): void
    {
        $this->seed();
        $this->actingAs(User::where('username', 'cashier')->firstOrFail());
        $body = ['submission_key' => (string) Str::uuid(), 'reason' => 'Wrong cart selected',
            'cart' => [['product_id' => 'slim', 'quantity' => 1]], 'approver_username' => 'missing-manager', 'approver_password' => 'incorrect'];
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/voids', $body)->assertUnprocessable();
        }
        $this->postJson('/api/voids', $body)->assertStatus(429);
        $this->assertDatabaseCount('void_records', 0);
    }
}
