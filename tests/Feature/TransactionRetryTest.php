<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransactionRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::where('username', 'cashier')->firstOrFail());
    }

    private function sale(): array
    {
        return ['submission_key' => (string) Str::uuid(), 'customer_id' => Customer::where('name', 'Walk-in Guest')->value('id'), 'order_type' => 'Walk-in', 'cash_tendered' => 100, 'items' => [['product_id' => 'slim', 'quantity' => 1]]];
    }

    public function test_sale_retry_returns_original_receipt_without_deducting_stock_again(): void
    {
        $payload = $this->sale();
        $first = $this->postJson('/api/transactions', $payload)->assertCreated();
        $stock = InventoryItem::pluck('stock_on_hand', 'id')->all();
        $sales = Transaction::count();
        $movements = DB::table('stock_movements')->count();
        $queue = DB::table('production_queue')->count();
        Product::whereKey('slim')->update(['price' => 99]);
        $this->postJson('/api/transactions', $payload)->assertCreated()->assertJsonPath('transaction.id', $first->json('transaction.id'))->assertJsonPath('totals.total', $first->json('totals.total'));
        $this->assertSame($stock, InventoryItem::pluck('stock_on_hand', 'id')->all());
        $this->assertSame($sales, Transaction::count());
        $this->assertSame($movements, DB::table('stock_movements')->count());
        $this->assertSame($queue, DB::table('production_queue')->count());
        $payload['items'][0]['quantity'] = 2;
        $this->postJson('/api/transactions', $payload)->assertStatus(409);
    }

    public function test_payment_retry_does_not_collect_twice_even_after_balance_is_cleared(): void
    {
        $c = Customer::where('name', 'Santos Family')->firstOrFail();
        $c->update(['debt_balance' => 150]);
        $data = ['submission_key' => (string) Str::uuid(), 'amount' => 150];
        $first = $this->postJson('/api/customers/'.$c->id.'/settle', $data)->assertOk();
        $count = Transaction::count();
        $this->postJson('/api/customers/'.$c->id.'/settle', $data)->assertOk()->assertJsonPath('transaction.id', $first->json('transaction.id'))->assertJsonPath('applied', 150);
        $this->assertSame($count, Transaction::count());
        $this->assertEquals(0, $c->fresh()->debt_balance);
        $data['amount'] = 100;
        $this->postJson('/api/customers/'.$c->id.'/settle', $data)->assertStatus(409);
    }

    public function test_failed_sale_can_be_retried_without_a_partial_submission(): void
    {
        $data = $this->sale();
        $cover = InventoryItem::where('item_name', 'CLEAR_COVER')->firstOrFail();
        $cover->update(['stock_on_hand' => 0]);
        $this->postJson('/api/transactions', $data)->assertUnprocessable();
        $this->assertDatabaseMissing('transactions', ['submission_key' => $data['submission_key']]);
        $cover->update(['stock_on_hand' => 50]);
        $this->postJson('/api/transactions', $data)->assertCreated();
    }

    public function test_submission_keys_are_required_and_separate_keys_allow_identical_sales(): void
    {
        $data = $this->sale();
        unset($data['submission_key']);
        $this->postJson('/api/transactions', $data)->assertUnprocessable()->assertJsonValidationErrors('submission_key');
        $c = Customer::where('name', 'Santos Family')->firstOrFail();
        $this->postJson('/api/customers/'.$c->id.'/settle', ['amount' => 10])->assertUnprocessable()->assertJsonValidationErrors('submission_key');
        $one = $this->postJson('/api/transactions', $this->sale())->assertCreated()->json('transaction.id');
        $two = $this->postJson('/api/transactions', $this->sale())->assertCreated()->json('transaction.id');
        $this->assertNotSame($one,$two);
    }

    public function test_delivery_retry_preserves_ledger_balance_and_operator_keys_are_isolated(): void
    {
        $customer = Customer::where('name', 'Santos Family')->firstOrFail();
        $payload = array_replace($this->sale(), ['customer_id' => $customer->id, 'order_type' => 'Delivery', 'cash_tendered' => 0]);
        $first = $this->postJson('/api/transactions', $payload)->assertCreated();
        $balance = $customer->fresh()->debt_balance;
        $this->postJson('/api/transactions', $payload)->assertCreated()->assertJsonPath('replayed', true);
        $this->assertEquals($balance, $customer->fresh()->debt_balance);
        $this->postJson('/api/customers/'.$customer->id.'/settle', ['submission_key' => $payload['submission_key'], 'amount' => 10])->assertStatus(409);
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $second = $this->postJson('/api/transactions', $payload)->assertCreated()->assertJsonPath('replayed', false);
        $this->assertNotSame($first->json('transaction.id'), $second->json('transaction.id'));
    }
}
