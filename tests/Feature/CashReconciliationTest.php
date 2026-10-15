<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
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

    public function test_collections_count_as_cash_without_becoming_sales(): void
    {
        $cashier = $this->cashier();
        $this->actingAs($cashier)->postJson('/api/cash-shift/open', ['opening_float' => 200])->assertOk();
        $this->recordSale($cashier, 'Cash', 300, 'Walk-in');
        $this->recordSale($cashier, 'Cash', 100, 'Debt Payment');
        $this->getJson('/api/cash-shift')->assertOk()
            ->assertJsonPath('shift.expected_cash', 600)
            ->assertJsonPath('shift.total_sales', 300)
            ->assertJsonPath('shift.cash_sales_count', 1);
    }

    public function test_cashier_history_excludes_other_operators(): void
    {
        $owner = $this->owner();
        $cashier = $this->cashier();
        $this->actingAs($owner)->postJson('/api/cash-shift/open', ['opening_float' => 100])->assertOk();
        $this->postJson('/api/cash-shift/close', ['counted_cash' => 100])->assertOk();
        $this->actingAs($cashier)->getJson('/api/cash-shift')->assertOk()->assertJsonCount(0, 'history');
        $this->actingAs($owner)->getJson('/api/cash-shift/overview')->assertOk()->assertJsonCount(1, 'history');
    }

    public function test_overnight_drawer_must_be_closed_before_more_money_is_recorded(): void
    {
        $cashier = $this->cashier();
        $this->travelTo(now()->startOfDay()->addHours(23));
        $this->actingAs($cashier)->postJson('/api/cash-shift/open', ['opening_float' => 100])->assertOk();
        $this->travel(2)->hours();
        $this->getJson('/api/cash-shift')->assertOk()->assertJsonPath('shift.is_open', true);
        $this->postJson('/api/cash-shift/open', ['opening_float' => 100])->assertConflict();
        $this->postJson('/api/cash-shift/close', ['counted_cash' => 100])->assertOk();
        $this->postJson('/api/cash-shift/open', ['opening_float' => 100])->assertOk();
    }

    public function test_stale_close_cannot_overwrite_a_finished_count(): void
    {
        $cashier = $this->cashier();
        $cash = app(CashReconciliationService::class);
        $shift = $cash->open($cashier, 100)['shift'];
        $cash->close($shift, 100);
        try {
            $cash->close($shift, 999);
            $this->fail('A stale close overwrote the final count.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('counted_cash', $exception->errors());
        }
        $this->assertEquals(100, $shift->fresh()->counted_cash);
    }

    private function recordSale(User $cashier, string $payment, float $total, string $orderType): void
    {
        $customer = Customer::create([
            'name' => 'Buyer '.$payment.uniqid(),
            'address' => 'Test',
            'contact' => '09000000000',
        ]);

        Transaction::create([
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
