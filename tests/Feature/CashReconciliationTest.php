<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::create([
            'name' => 'Owner', 'username' => 'admin', 'password' => 'Owner123',
            'role' => User::ROLE_ADMIN, 'email' => 'owner@example.com',
            'email_verified_at' => now(), 'is_active' => true, 'is_bootstrap' => false,
        ]);
    }

    private function cashier(): User
    {
        return User::create([
            'name' => 'Cashier', 'username' => 'cashier', 'password' => 'Cashier123',
            'role' => User::ROLE_CASHIER, 'is_active' => true,
        ]);
    }

    public function test_guest_cannot_reconcile(): void
    {
        $this->getJson('/api/cash-shift')->assertUnauthorized();
        $this->postJson('/api/cash-shift/open', ['opening_float' => 500])->assertUnauthorized();
    }

    public function test_owner_can_open_and_close_a_balanced_drawer(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        $opened = $this->postJson('/api/cash-shift/open', ['opening_float' => 500]);
        $opened->assertOk()->assertJsonPath('shift.opening_float', 500);
        $opened->assertJsonPath('shift.expected_cash', 500);

        $closed = $this->postJson('/api/cash-shift/close', ['counted_cash' => 500]);
        $closed->assertOk()
            ->assertJsonPath('shift.variance', 0)
            ->assertJsonPath('shift.status', 'balanced');
    }

    public function test_short_drawer_is_reported(): void
    {
        $this->actingAs($this->owner());
        $this->postJson('/api/cash-shift/open', ['opening_float' => 500]);

        $this->postJson('/api/cash-shift/close', ['counted_cash' => 450])
            ->assertOk()
            ->assertJsonPath('shift.variance', -50)
            ->assertJsonPath('shift.status', 'short');
    }

    public function test_over_drawer_is_reported(): void
    {
        $this->actingAs($this->owner());
        $this->postJson('/api/cash-shift/open', ['opening_float' => 500]);

        $this->postJson('/api/cash-shift/close', ['counted_cash' => 525])
            ->assertOk()
            ->assertJsonPath('shift.status', 'over');
    }

    public function test_expected_cash_counts_only_cash_sales(): void
    {
        $cashier = $this->cashier();
        $this->actingAs($cashier);

        $this->postJson('/api/cash-shift/open', ['opening_float' => 200]);

        // A cash sale and an account (credit) sale. Only the cash one lands in
        // the drawer, so expected must not include the credit amount.
        $this->recordSale($cashier, 'Cash', 300.00, 'Walk-in');
        $this->recordSale($cashier, 'Account', 900.00, 'Walk-in');

        $expected = $this->getJson('/api/cash-shift')->assertOk()
            ->json('shift.expected_cash');

        $this->assertEqualsWithDelta(500.0, $expected, 0.01);
    }

    public function test_cannot_open_two_drawers_in_one_day(): void
    {
        $this->actingAs($this->owner());
        $this->postJson('/api/cash-shift/open', ['opening_float' => 100])->assertOk();
        $this->postJson('/api/cash-shift/open', ['opening_float' => 999])->assertStatus(409);
    }

    public function test_cannot_close_without_opening(): void
    {
        $this->actingAs($this->owner());
        $this->postJson('/api/cash-shift/close', ['counted_cash' => 100])
            ->assertStatus(422);
    }

    public function test_cashier_cannot_read_the_owner_overview(): void
    {
        $this->actingAs($this->cashier());
        $this->getJson('/api/cash-shift/overview')->assertStatus(403);
    }

    public function test_owner_can_read_the_overview(): void
    {
        $this->actingAs($this->owner());
        $this->getJson('/api/cash-shift/overview')->assertOk()->assertJsonStructure(['shifts', 'history']);
    }

    public function test_reconciliation_page_renders_for_owner(): void
    {
        $this->actingAs($this->owner());
        $this->get('/admin/cash')
            ->assertOk()
            ->assertSee('Cash Reconciliation', false)
            ->assertSee('id="cashFloat"', false)
            ->assertSee('id="cashCounted"', false);
    }

    private function recordSale(User $cashier, string $payment, float $total, string $orderType): void
    {
        $customer = \App\Models\Customer::create([
            'name' => 'Buyer '.$payment.uniqid(),
            'address' => 'Test',
            'contact' => '09000000000',
        ]);

        \App\Models\Transaction::create([
            'receipt_number' => 'OR-'.uniqid(),
            'customer_id' => $customer->id,
            'cashier_id' => $cashier->id,
            'order_type' => $orderType,
            'gallons_volume' => '5S',
            'subtotal_amount' => $total,
            'vat_amount' => 0,
            'total_amount' => $total,
            'payment_method' => $payment,
            'transaction_date' => today()->toDateString(),
            'transaction_time' => now()->toTimeString(),
        ]);
    }
}