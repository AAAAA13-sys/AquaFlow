<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DeliveryProof;
use App\Models\DeliveryRemittance;
use App\Models\ProductionQueueItem;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityCashFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function receipt(User $cashier, Customer $customer, string $number, float $amount, string $method = 'Cash', string $type = 'Walk-in'): Transaction
    {
        return Transaction::create([
            'receipt_number' => $number, 'customer_id' => $customer->id, 'cashier_id' => $cashier->id,
            'order_type' => $type, 'payment_method' => $method, 'total_amount' => $amount,
            'transaction_date' => today(), 'transaction_time' => '09:30:00',
        ]);
    }

    public function test_activity_cash_excludes_credit_electronic_and_voided_receipts(): void
    {
        $cashier = User::create(['name' => 'Cashier', 'username' => 'activity_cash', 'password' => 'Private123!', 'role' => 'cashier', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Buyer']);
        $this->receipt($cashier, $customer, 'CASH', 100);
        $this->receipt($cashier, $customer, 'COLLECTION', 25, 'Cash', 'Debt Payment');
        $this->receipt($cashier, $customer, 'CREDIT', 200, 'Account', 'Delivery');
        $this->receipt($cashier, $customer, 'ELECTRONIC', 300, 'GCash');
        $void = $this->receipt($cashier, $customer, 'VOID', 400);
        $void->forceFill(['voided_at' => now()])->save();

        $response = $this->actingAs($cashier)->getJson('/api/activity')->assertOk()->assertJsonPath('counts.cash', 125);
        $this->assertContains('CREDIT', array_column($response->json('entries'), 'title'));
        $this->assertContains('VOID', array_column($response->json('entries'), 'title'));
    }

    public function test_activity_counts_rider_cash_once_through_its_payment_receipt(): void
    {
        $cashier = User::create(['name' => 'Cashier', 'username' => 'activity_cash', 'password' => 'Private123!', 'role' => 'cashier', 'is_active' => true]);
        $rider = User::create(['name' => 'Rider', 'username' => 'activity_rider', 'password' => 'Private123!', 'role' => 'rider', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Buyer']);
        $sale = $this->receipt($cashier, $customer, 'DELIVERY', 100, 'Account', 'Delivery');
        $payment = $this->receipt($cashier, $customer, 'REMITTANCE', 100, 'Cash', 'Debt Payment');
        $job = ProductionQueueItem::create(['transaction_id' => $sale->id, 'receipt_number' => 'DELIVERY', 'customer_name' => 'Buyer', 'order_type' => 'Delivery', 'items_description' => 'Water', 'is_completed' => true]);
        $proof = DeliveryProof::create(['production_queue_id' => $job->id, 'customer_id' => $customer->id, 'rider_id' => $rider->id, 'photo_path' => 'proof.jpg', 'photo_hash' => str_repeat('a', 64), 'recipient_name' => 'Buyer', 'received_quantity' => 1, 'reported_cash' => 100, 'created_at' => now()]);
        DeliveryRemittance::create(['delivery_proof_id' => $proof->id, 'cashier_id' => $cashier->id, 'transaction_id' => $payment->id, 'amount' => 100, 'created_at' => now()]);

        $response = $this->actingAs($cashier)->getJson('/api/activity')->assertOk()->assertJsonPath('counts.cash', 100);
        $this->assertContains('remittance', array_column($response->json('entries'), 'kind'));

        $payment->forceFill(['voided_at' => now()])->save();
        $this->getJson('/api/activity')->assertOk()->assertJsonPath('counts.cash', 0);
    }
}
