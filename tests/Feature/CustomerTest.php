<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->cashier = User::query()->where('username', 'cashier')->firstOrFail();
        // Historical balances belong to test fixtures, never fresh-store seed data.
        Customer::where('name', 'Santos Family')->update(['debt_balance' => 150]);
        Customer::where('name', 'Dela Cruz Residence')->update(['issued_round' => 4, 'returned_round' => 3]);
        Customer::where('name', 'San Miguel Commercial Canteen')->update(['issued_slim' => 45, 'returned_slim' => 30]);
    }

    public function test_customers_can_be_listed_and_searched(): void
    {
        $this->actingAs($this->cashier)
            ->getJson('/api/customers')
            ->assertOk()
            ->assertJsonCount(10, 'customers');

        $this->actingAs($this->cashier)
            ->getJson('/api/customers?q=Santos')
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.name', 'Santos Family');
    }

    public function test_customers_can_be_filtered_by_bottles_or_debt(): void
    {
        $withBottles = $this->actingAs($this->cashier)->getJson('/api/customers?filter=bottles')->json('customers');
        $withDebt = $this->actingAs($this->cashier)->getJson('/api/customers?filter=cash')->json('customers');

        $this->assertNotEmpty($withBottles);
        $this->assertNotEmpty($withDebt);

        foreach ($withDebt as $customer) {
            $this->assertGreaterThan(0, $customer['debt']);
        }
    }

    public function test_a_customer_can_be_registered(): void
    {
        $response = $this->actingAs($this->cashier)->postJson('/api/customers', [
            'name' => 'New Walk-in Customer',
            'address' => 'Block 1 Lot 2',
            'contact' => '0900-111-2222',
        ]);

        $response->assertCreated()
            ->assertJsonPath('customer.name', 'New Walk-in Customer')
            ->assertJsonPath('customer.debt', 0);

        $this->assertDatabaseHas('customers', ['name' => 'New Walk-in Customer']);
    }

    public function test_registration_requires_a_name(): void
    {
        $this->actingAs($this->cashier)
            ->postJson('/api/customers', ['name' => ''])
            ->assertStatus(422);
    }

    public function test_a_partial_debt_payment_reduces_the_balance(): void
    {
        $customer = Customer::query()->where('name', 'Santos Family')->firstOrFail();
        $before = (float) $customer->debt_balance; // 150.00

        $response = $this->actingAs($this->cashier)
            ->postJson('/api/customers/'.$customer->id.'/settle', ['submission_key' => (string) Str::uuid(), 'amount' => 100]);

        $response->assertOk()
            ->assertJsonPath('applied', 100);

        // Whole-number floats serialise as JSON integers, so compare numerically.
        $this->assertEqualsWithDelta($before - 100, (float) $response->json('customer.debt'), 0.001);
    }

    public function test_a_payment_never_exceeds_the_outstanding_balance(): void
    {
        $customer = Customer::query()->where('name', 'Santos Family')->firstOrFail();

        $response = $this->actingAs($this->cashier)
            ->postJson('/api/customers/'.$customer->id.'/settle', ['submission_key' => (string) Str::uuid(), 'amount' => 99999]);

        $response->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertEquals(150, $customer->refresh()->debt_balance);
    }

    public function test_collection_rejects_fractional_cents(): void
    {
        $customer = Customer::where('name', 'Santos Family')->firstOrFail();
        $this->actingAs($this->cashier)->postJson('/api/customers/'.$customer->id.'/settle', [
            'submission_key' => (string) Str::uuid(), 'amount' => 1.001,
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertEquals(150, $customer->refresh()->debt_balance);
    }

    public function test_customer_name_requires_letters_only_without_numbers(): void
    {
        $this->actingAs($this->cashier)->postJson('/api/customers', ['name' => '1234'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($this->cashier)->postJson('/api/customers', ['name' => 'Block 6 Residents'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($this->cashier)->postJson('/api/customers', ['name' => 'Juan Dela Cruz', 'contact' => '09123456789'])
            ->assertCreated();
    }

    public function test_paying_a_settled_account_is_rejected(): void
    {
        $customer = Customer::query()->where('name', 'Santos Family')->firstOrFail();
        $customer->update(['debt_balance' => 0]);

        $this->actingAs($this->cashier)
            ->postJson('/api/customers/'.$customer->id.'/settle', ['submission_key' => (string) Str::uuid(), 'amount' => 10])
            ->assertStatus(422);
    }

    public function test_a_bottle_return_clears_part_of_the_custody_balance(): void
    {
        // Dela Cruz Residence: issued 4 round, returned 3 -> one pending.
        $customer = Customer::query()->where('name', 'Dela Cruz Residence')->firstOrFail();
        $this->assertSame(1, $customer->pendingRound());

        $response = $this->actingAs($this->cashier)
            ->postJson('/api/customers/'.$customer->id.'/returns', ['kind' => 'round']);

        $response->assertStatus(410);
        $this->assertSame(1, $customer->refresh()->pendingRound());
    }

    public function test_a_return_with_no_pending_bottles_is_rejected(): void
    {
        $customer = Customer::query()->where('name', 'Dela Cruz Residence')->firstOrFail();

        $this->actingAs($this->cashier)
            ->postJson('/api/customers/'.$customer->id.'/returns', ['kind' => 'slim'])
            ->assertStatus(410);
    }

    public function test_a_customer_detail_drawer_payload_has_pending_counts(): void
    {
        $response = $this->actingAs($this->cashier)->getJson('/api/customers?q=San Miguel');

        $response->assertOk();
        $customer = $response->json('customers.0');

        $this->assertSame(15, $customer['pending']['slim']); // 45 issued - 30 returned
        $this->assertSame(0, $customer['pending']['round']);
    }

    public function test_unknown_customer_returns_404(): void
    {
        $this->actingAs($this->cashier)
            ->postJson('/api/customers/99999/settle', ['submission_key' => (string) Str::uuid(), 'amount' => 10])
            ->assertStatus(404);
    }
}
