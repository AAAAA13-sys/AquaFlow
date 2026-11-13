<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerStatementFeatureTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $cashier;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::where('username', 'admin')->firstOrFail();
        $this->cashier = User::where('username', 'cashier')->firstOrFail();
        $this->customer = Customer::where('name', 'Santos Family')->firstOrFail();
        // Debt carried in from before the transaction history started.
        $this->customer->update(['debt_balance' => 150]);
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone'))->startOfDay();
    }

    private function accountSale(int $quantity = 2): Transaction
    {
        $this->openDrawer();
        $response = $this->actingAs($this->cashier)->postJson('/api/transactions', [
            'submission_key' => (string) Str::uuid(),
            'customer_id' => $this->customer->id,
            'order_type' => 'Delivery',
            'payment_method' => 'Account',
            'items' => [['product_id' => 'slim', 'quantity' => $quantity]],
        ])->assertCreated();
        $this->assertSame('Account', $response->json('transaction.pay'));

        return Transaction::findOrFail($response->json('transaction.id'));
    }

    private function settle(float $amount): Transaction
    {
        $response = $this->actingAs($this->owner)->postJson('/api/customers/'.$this->customer->id.'/settle', [
            'submission_key' => (string) Str::uuid(), 'amount' => $amount,
        ])->assertOk();

        return Transaction::findOrFail($response->json('transaction.id'));
    }

    private function statement(array $query = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->getJson('/api/customers/'.$this->customer->id.'/statement?'.http_build_query($query));
    }

    public function test_statement_opening_lines_and_closing_reconcile_with_debt_balance(): void
    {
        $sale = $this->accountSale();   // +70 -> 220
        $payment = $this->settle(100);  // -100 -> 120
        $this->assertEqualsWithDelta(120, (float) $this->customer->fresh()->debt_balance, 0.001);

        $response = $this->statement()->assertOk()
            ->assertJsonPath('customer.id', $this->customer->id)
            ->assertJsonPath('customer.name', 'Santos Family')
            ->assertJsonStructure(['customer' => ['id', 'name', 'address', 'contact'], 'period' => ['from', 'to'],
                'opening_balance', 'lines' => [['date', 'time', 'receipt_number', 'description', 'charge', 'payment', 'balance_after']],
                'totals' => ['charges', 'payments'], 'closing_balance', 'current_balance']);

        $this->assertEqualsWithDelta(150, $response->json('opening_balance'), 0.001);
        $lines = $response->json('lines');
        $this->assertCount(2, $lines);
        $this->assertSame($sale->receipt_number, $lines[0]['receipt_number']);
        $this->assertEqualsWithDelta(70, $lines[0]['charge'], 0.001);
        $this->assertEqualsWithDelta(0, $lines[0]['payment'], 0.001);
        $this->assertEqualsWithDelta(220, $lines[0]['balance_after'], 0.001);
        $this->assertSame($payment->receipt_number, $lines[1]['receipt_number']);
        $this->assertEqualsWithDelta(100, $lines[1]['payment'], 0.001);
        $this->assertEqualsWithDelta(120, $lines[1]['balance_after'], 0.001);
        $this->assertEqualsWithDelta(70, $response->json('totals.charges'), 0.001);
        $this->assertEqualsWithDelta(100, $response->json('totals.payments'), 0.001);
        $this->assertEqualsWithDelta(120, $response->json('closing_balance'), 0.001);
        $this->assertEqualsWithDelta((float) $this->customer->fresh()->debt_balance, $response->json('closing_balance'), 0.001);
        $this->assertEqualsWithDelta(120, $response->json('current_balance'), 0.001);
    }

    public function test_voided_account_sales_net_to_zero_and_do_not_distort_balances(): void
    {
        $this->accountSale(1);              // +35 -> 185
        $voided = $this->accountSale(2);    // +70 -> 255
        $voided->forceFill(['voided_at' => now()])->save();
        $this->customer->update(['debt_balance' => 185]); // what VoidService leaves behind

        $response = $this->statement()->assertOk();
        $this->assertCount(1, $response->json('lines'));
        $this->assertEqualsWithDelta(150, $response->json('opening_balance'), 0.001);
        $this->assertEqualsWithDelta(185, $response->json('closing_balance'), 0.001);
        // The surviving line is re-based; it never shows the voided sale's 255.
        $this->assertEqualsWithDelta(185, $response->json('lines.0.balance_after'), 0.001);
    }

    public function test_period_filter_sets_opening_balance_from_prior_activity(): void
    {
        $old = $this->accountSale();     // +70
        $this->settle(40);               // -40 -> 180
        $recent = $this->accountSale(1); // +35 -> 215
        $old->forceFill(['transaction_date' => $this->today()->subDays(20)->toDateString()])->save();
        Transaction::where('order_type', 'Debt Payment')->update(['transaction_date' => $this->today()->subDays(10)->toDateString()]);

        $response = $this->statement(['from' => $this->today()->subDays(5)->toDateString(), 'to' => $this->today()->toDateString()])->assertOk();

        // 150 carried in + 70 charged 20 days ago - 40 paid 10 days ago = 180 before the window.
        $this->assertEqualsWithDelta(180, $response->json('opening_balance'), 0.001);
        $this->assertCount(1, $response->json('lines'));
        $this->assertSame($recent->receipt_number, $response->json('lines.0.receipt_number'));
        $this->assertEqualsWithDelta(215, $response->json('closing_balance'), 0.001);

        // A window that ended before the later activity closes at the earlier balance.
        $past = $this->statement(['from' => $this->today()->subDays(30)->toDateString(), 'to' => $this->today()->subDays(15)->toDateString()])->assertOk();
        $this->assertEqualsWithDelta(150, $past->json('opening_balance'), 0.001);
        $this->assertEqualsWithDelta(220, $past->json('closing_balance'), 0.001);
        $this->assertEqualsWithDelta(215, $past->json('current_balance'), 0.001);
    }

    public function test_default_period_is_the_last_30_days(): void
    {
        $response = $this->statement()->assertOk();
        $this->assertSame($this->today()->subDays(29)->toDateString(), $response->json('period.from'));
        $this->assertSame($this->today()->toDateString(), $response->json('period.to'));
    }

    public function test_walk_in_guest_has_no_statement(): void
    {
        $guest = Customer::where('system_key', 'walk-in')->firstOrFail();
        $this->actingAs($this->owner)->getJson('/api/customers/'.$guest->id.'/statement')->assertStatus(404);
    }

    public function test_unknown_customer_is_404(): void
    {
        $this->actingAs($this->owner)->getJson('/api/customers/999999/statement')->assertStatus(404);
    }

    public function test_cashier_cannot_read_statements(): void
    {
        $this->statement([], $this->cashier)->assertForbidden();
    }

    public function test_range_over_366_days_rejected(): void
    {
        $this->statement(['from' => $this->today()->subDays(400)->toDateString(), 'to' => $this->today()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->statement(['from' => $this->today()->toDateString(), 'to' => $this->today()->subDay()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->statement(['from' => 'garbage'])->assertUnprocessable()->assertJsonValidationErrors('from');
    }
}
