<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OctoberUpdatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_early_arrival_needs_no_reason_and_departure_uses_nine_elapsed_hours(): void
    {
        $this->seed();
        $this->actingAs(User::firstOrCreate(['username' => 'refiller'], ['name' => 'Refiller', 'password' => 'Refill123', 'role' => 'refiller', 'employee_id' => Employee::firstOrFail()->id, 'is_active' => true]));
        $url = '/api/employee/attendance';
        $this->travelTo(now()->setTime(7, 0, 0));
        $this->postJson($url.'/time-in')->assertOk()->assertJsonPath('row.expected_time_out', now()->setTime(16, 0)->format('Y-m-d\TH:i'));
        $this->travelTo(now()->setTime(15, 59, 59));
        $this->postJson($url.'/time-out')->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->travelTo(now()->setTime(16, 0, 0));
        $this->postJson($url.'/time-out')->assertOk();
    }

    public function test_transaction_range_includes_selected_minutes_and_rejects_reversed_dates(): void
    {
        $this->seed();
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->actingAs($admin);
        $customer = \App\Models\Customer::firstOrCreate(['name' => 'Test Cust'], ['customer_type' => 'regular']);
        $sale = Transaction::first() ?? Transaction::create(['receipt_number' => 'OR-OCT-001', 'total_amount' => 50, 'cashier_id' => $admin->id, 'customer_id' => $customer->id, 'order_type' => 'Walk-in', 'payment_method' => 'Cash', 'transaction_date' => '2026-02-03', 'transaction_time' => '10:15:59']);
        $sale->forceFill(['transaction_date' => '2026-02-03', 'transaction_time' => '10:15:59'])->save();
        $url = '/api/transactions?from=2026-02-03T10:15&to=2026-02-03T10:15';
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'transactions')->assertJsonPath('transactions.0.id', $sale->id);
        $this->getJson('/api/transactions?from=2026-02-03T10:16&to=2026-02-03T11:00')->assertOk()->assertJsonCount(0, 'transactions');
        $this->getJson('/api/transactions?from=2026-02-04T00:00&to=2026-02-03T23:59')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/api/transactions?from=invalid')->assertUnprocessable()->assertJsonValidationErrors('from');
    }
}
