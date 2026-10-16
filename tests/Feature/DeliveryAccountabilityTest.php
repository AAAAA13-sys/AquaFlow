<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DeliveryProof;
use App\Models\Employee;
use App\Models\ProductionQueueItem;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeliveryAccountabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private User $rider;

    private Customer $buyer;

    private ProductionQueueItem $job;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        $this->cashier = User::where('role', 'cashier')->firstOrFail();
        $this->rider = User::where('role', 'rider')->firstOrFail();
        $this->buyer = Customer::whereNull('system_key')->firstOrFail();
        $this->buyer->update(['debt_balance' => 100]);
        $sale = Transaction::create(['receipt_number' => 'DELIVERY-QA', 'customer_id' => $this->buyer->id,
            'cashier_id' => $this->cashier->id, 'order_type' => 'Delivery', 'payment_method' => 'Account',
            'total_amount' => 100, 'payment_status' => 'unpaid', 'transaction_date' => today(), 'transaction_time' => '08:00:00']);
        TransactionItem::create(['transaction_id' => $sale->id, 'product_id' => 'slim', 'item_name' => 'Refill', 'quantity' => 2, 'unit_price' => 50, 'line_total' => 100]);
        $this->job = ProductionQueueItem::create(['transaction_id' => $sale->id, 'receipt_number' => $sale->receipt_number,
            'customer_name' => $this->buyer->name, 'items_description' => '2 refills', 'order_type' => 'Delivery']);
        $this->job->forceFill(['rider_id' => $this->rider->id, 'fulfillment_status' => 'ready'])->save();
    }

    private function action(string $action, array $extra = []): array
    {
        return $extra + ['action' => $action, 'version' => $this->job->fresh()->version, 'submission_key' => (string) Str::uuid()];
    }

    private function handoverAndDispatch(): void
    {
        $this->actingAs($this->cashier)->postJson('/api/fulfillment/'.$this->job->id, $this->action('handover', ['handover_quantity' => 2]))->assertOk();
        $this->actingAs($this->rider)->postJson('/api/employee/orders/'.$this->job->id, $this->action('dispatch'))->assertOk();
    }

    private function evidence(array $extra = []): array
    {
        return $extra + $this->action('deliver', ['photo' => UploadedFile::fake()->createWithContent('delivery.png', file_get_contents(base_path('tests/Fixtures/delivery.png'))),
            'recipient_name' => 'Buyer Recipient', 'recipient_code' => $this->job->fresh()->delivery_code,
            'received_quantity' => 2, 'reported_cash' => 100]);
    }

    private function deliver(array $extra = [])
    {
        return $this->actingAs($this->rider)->post('/api/employee/orders/'.$this->job->id, $this->evidence($extra), ['Accept' => 'application/json']);
    }

    public function test_cashier_handover_is_required_and_code_is_hidden_from_rider(): void
    {
        $this->actingAs($this->rider)->postJson('/api/employee/orders/'.$this->job->id, $this->action('dispatch'))->assertUnprocessable();
        $this->actingAs($this->cashier)->postJson('/api/fulfillment/'.$this->job->id, $this->action('handover', ['handover_quantity' => 1]))->assertUnprocessable();
        $this->handoverAndDispatch();
        $code = $this->job->fresh()->delivery_code;
        $this->assertMatchesRegularExpression('/^[0-9]{8}$/', $code);
        $this->assertNotSame($code, $this->job->fresh()->getRawOriginal('delivery_code'));
        $this->getJson('/api/employee/orders')->assertOk()->assertJsonPath('orders.0.confirmation_code', null)->assertDontSee($code);
        $this->actingAs($this->cashier)->getJson('/api/fulfillment')->assertOk()->assertJsonFragment(['confirmation_code' => $code]);
    }

    public function test_delivery_requires_valid_photo_buyer_code_quantity_and_cash_declaration(): void
    {
        $this->handoverAndDispatch();
        $this->actingAs($this->rider)->postJson('/api/employee/orders/'.$this->job->id, $this->action('deliver'))->assertUnprocessable()->assertJsonValidationErrors(['photo', 'recipient_name', 'recipient_code', 'received_quantity', 'reported_cash']);
        $this->deliver(['photo' => UploadedFile::fake()->createWithContent('fake.jpg', 'not an image')])->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->deliver(['recipient_code' => '00000000'])->assertUnprocessable();
        $this->deliver(['received_quantity' => 1])->assertUnprocessable();
        $this->deliver(['reported_cash' => 101])->assertUnprocessable();
        $this->deliver(['reported_cash' => 50])->assertUnprocessable()->assertJsonValidationErrors('collection_note');
        $this->assertDatabaseCount('delivery_proofs', 0);
        $this->assertFalse($this->job->fresh()->is_completed);
        $this->assertEquals(100, $this->buyer->fresh()->debt_balance);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_delivery_and_cash_report_do_not_settle_debt_until_cashier_accepts_remittance(): void
    {
        $this->handoverAndDispatch();
        $payload = $this->evidence();
        $this->actingAs($this->rider)->post('/api/employee/orders/'.$this->job->id, $payload, ['Accept' => 'application/json'])->assertOk();
        $this->post('/api/employee/orders/'.$this->job->id, $payload, ['Accept' => 'application/json'])->assertOk();
        $this->assertDatabaseCount('delivery_proofs', 1);
        $this->assertEquals(100, $this->buyer->fresh()->debt_balance);
        $this->assertDatabaseCount('transactions', 1);
        Storage::disk('local')->assertExists(DeliveryProof::firstOrFail()->photo_path);
        $this->actingAs($this->cashier);
        $this->postJson('/api/customers/'.$this->buyer->id.'/settle', ['amount' => 100, 'submission_key' => (string) Str::uuid()])->assertUnprocessable();
        $remit = ['amount' => 100, 'submission_key' => (string) Str::uuid()];
        $response = $this->postJson('/api/deliveries/'.$this->job->id.'/remit', $remit)->assertOk();
        $this->postJson('/api/deliveries/'.$this->job->id.'/remit', $remit)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('transaction.no', $response->json('transaction.no'));
        $this->assertEquals(0, $this->buyer->fresh()->debt_balance);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('delivery_remittances', 1);
        $this->assertEquals('paid', $this->job->transaction->fresh()->payment_status);
        $this->postJson('/api/deliveries/'.$this->job->id.'/remit', ['amount' => 100, 'submission_key' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_short_remittance_requires_explanation_and_keeps_the_remainder_due(): void
    {
        $this->handoverAndDispatch();
        $this->deliver()->assertOk();
        $this->actingAs($this->cashier);
        $this->postJson('/api/deliveries/'.$this->job->id.'/remit', ['amount' => 60, 'submission_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson('/api/deliveries/'.$this->job->id.'/remit', ['amount' => 60, 'submission_key' => (string) Str::uuid(), 'note' => 'Rider is short forty pesos'])->assertOk();
        $this->assertEquals(40, $this->buyer->fresh()->debt_balance);
        $this->travel(10)->days();
        $this->getJson('/api/fulfillment')->assertOk()->assertJsonFragment(['pending_remittance' => 40]);
        $this->actingAs($this->rider)->getJson('/api/employee/orders')->assertOk()->assertJsonFragment(['pending_remittance' => 40]);
        $this->actingAs($this->cashier);
        $this->postJson('/api/deliveries/'.$this->job->id.'/remit', ['amount' => 40, 'submission_key' => (string) Str::uuid()])->assertOk();
        $this->assertEquals(0, $this->buyer->fresh()->debt_balance);
    }

    public function test_proofs_are_private_and_riders_cannot_accept_their_own_remittance(): void
    {
        $this->handoverAndDispatch();
        $this->deliver()->assertOk();
        $this->get('/api/employee/orders/'.$this->job->id.'/proof')->assertNotFound();
        $this->postJson('/api/deliveries/'.$this->job->id.'/remit', ['amount' => 100, 'submission_key' => (string) Str::uuid()])->assertForbidden();
        $refiller = User::where('role', 'refiller')->firstOrFail();
        $this->actingAs($refiller)->getJson('/api/employee/orders/'.$this->job->id.'/proof')->assertNotFound();
        $employee = Employee::create(['name' => 'Other Rider', 'job_title' => 'Rider', 'is_active' => true]);
        $other = User::create(['username' => 'other_rider', 'name' => 'Other', 'role' => 'rider', 'password' => 'Other123', 'employee_id' => $employee->id, 'is_active' => true]);
        $this->actingAs($other)->getJson('/api/employee/orders/'.$this->job->id.'/proof')->assertNotFound();
        $this->actingAs($this->cashier)->get('/api/deliveries/'.$this->job->id.'/proof')->assertOk();
        $this->assertEquals(100, $this->buyer->fresh()->debt_balance);
    }

    public function test_remittance_needs_no_drawer_and_conflicting_retries_are_rejected(): void
    {
        $this->handoverAndDispatch();
        $this->deliver()->assertOk();
        $this->actingAs($this->cashier);
        $body = ['amount' => 100, 'submission_key' => (string) Str::uuid()];
        $this->postJson('/api/deliveries/'.$this->job->id.'/remit', $body)->assertOk();
        $this->postJson('/api/deliveries/'.$this->job->id.'/remit', $body)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson('/api/deliveries/'.$this->job->id.'/remit', ['amount' => 99, 'submission_key' => $body['submission_key']])->assertConflict();
        $this->assertDatabaseCount('delivery_remittances', 1);
    }

    public function test_exact_photo_reuse_is_rejected_and_delivery_retry_cannot_change_cash(): void
    {
        $this->handoverAndDispatch();
        $payload = $this->evidence();
        $this->actingAs($this->rider)->post('/api/employee/orders/'.$this->job->id, $payload, ['Accept' => 'application/json'])->assertOk();
        $payload['reported_cash'] = 0;
        $this->post('/api/employee/orders/'.$this->job->id, $payload, ['Accept' => 'application/json'])->assertConflict();
        $this->buyer->update(['debt_balance' => 200]);
        $sale = $this->job->transaction->replicate();
        $sale->receipt_number = 'DELIVERY-SECOND';
        $sale->save();
        $line = $this->job->transaction->items->first()->replicate();
        $line->transaction_id = $sale->id;
        $line->save();
        $second = $this->job->fresh()->replicate();
        $second->forceFill(['transaction_id' => $sale->id, 'receipt_number' => $sale->receipt_number, 'fulfillment_status' => 'out_for_delivery', 'is_completed' => false, 'delivered_at' => null])->save();
        $this->job = $second;
        $this->deliver()->assertUnprocessable();
        $this->assertDatabaseCount('delivery_proofs', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertEquals(200, $this->buyer->fresh()->debt_balance);
    }

    public function test_partial_collection_keeps_uncollected_debt_and_replacement_codes_invalidate_old_ones(): void
    {
        $this->handoverAndDispatch();
        $oldCode = $this->job->fresh()->delivery_code;
        $this->actingAs($this->cashier)->postJson('/api/fulfillment/'.$this->job->id, $this->action('reset_code'))->assertOk();
        $this->deliver(['recipient_code' => $oldCode])->assertUnprocessable();
        $this->deliver(['reported_cash' => 60, 'collection_note' => 'Buyer paid sixty pesos only'])->assertOk();
        $this->actingAs($this->cashier);
        $this->postJson('/api/deliveries/'.$this->job->id.'/remit', ['amount' => 60, 'submission_key' => (string) Str::uuid()])->assertOk();
        $this->assertEquals(40, $this->buyer->fresh()->debt_balance);
        $this->assertEquals('unpaid', $this->job->transaction->fresh()->payment_status);
        $this->getJson('/api/fulfillment')->assertOk()->assertJsonFragment(['pending_remittance' => 0]);
    }

    public function test_history_remains_reviewable_and_referenced_staff_cannot_be_deleted(): void
    {
        $this->handoverAndDispatch();
        $this->deliver()->assertOk();
        $this->travel(10)->days();
        $owner = User::where('role', 'super_admin')->firstOrFail();
        $this->actingAs($owner)->getJson('/api/deliveries/'.$this->job->id)->assertOk()->assertJsonPath('order.proof.reported_cash', 100)->assertJsonCount(0, 'order.proof.remittances');
        $this->deleteJson('/api/users/'.$this->rider->id)->assertUnprocessable()->assertJsonValidationErrors('user');
        $this->deleteJson('/api/users/'.$this->cashier->id)->assertUnprocessable()->assertJsonValidationErrors('user');
        $this->assertDatabaseHas('users', ['id' => $this->rider->id]);
        $this->assertDatabaseCount('delivery_proofs', 1);
    }

    public function test_prepaid_deliveries_cannot_create_a_second_cash_collection(): void
    {
        $this->buyer->update(['debt_balance' => 0]);
        $this->job->transaction->update(['payment_method' => 'Cash', 'payment_status' => 'paid']);
        $this->handoverAndDispatch();
        $this->getJson('/api/employee/orders')->assertOk()->assertJsonFragment(['collection_limit' => 0]);
        $this->deliver(['reported_cash' => 1])->assertUnprocessable();
        $this->deliver(['reported_cash' => 0])->assertOk();
        $this->actingAs($this->cashier);
        $this->postJson('/api/deliveries/'.$this->job->id.'/remit', ['amount' => 1, 'submission_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('delivery_remittances', 0);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertEquals(0, $this->buyer->fresh()->debt_balance);
    }

    public function test_unreserved_debt_can_be_paid_without_consuming_pending_rider_cash(): void
    {
        $this->buyer->update(['debt_balance' => 150]);
        $this->handoverAndDispatch();
        $this->deliver()->assertOk();
        $this->actingAs($this->cashier);
        $this->postJson('/api/customers/'.$this->buyer->id.'/settle', ['amount' => 50, 'submission_key' => (string) Str::uuid()])->assertOk();
        $this->assertEquals(100, $this->buyer->fresh()->debt_balance);
        $this->postJson('/api/customers/'.$this->buyer->id.'/settle', ['amount' => 1, 'submission_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson('/api/deliveries/'.$this->job->id.'/remit', ['amount' => 100, 'submission_key' => (string) Str::uuid()])->assertOk();
        $this->assertEquals(0, $this->buyer->fresh()->debt_balance);
    }

    public function test_two_cashiers_share_one_remittance_balance_and_get_only_the_cash_they_accept(): void
    {
        $this->handoverAndDispatch();
        $this->deliver()->assertOk();
        $this->actingAs($this->cashier);
        $this->postJson('/api/deliveries/'.$this->job->id.'/remit', ['amount' => 40, 'submission_key' => (string) Str::uuid(), 'note' => 'First cash handover of forty'])->assertOk();
        $owner = User::where('role', 'super_admin')->firstOrFail();
        $this->actingAs($owner);
        $this->postJson('/api/deliveries/'.$this->job->id.'/remit', ['amount' => 100, 'submission_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson('/api/deliveries/'.$this->job->id.'/remit', ['amount' => 60, 'submission_key' => (string) Str::uuid()])->assertOk();
        $this->actingAs($this->cashier);
        $this->assertEquals(0, $this->buyer->fresh()->debt_balance);
        $this->assertDatabaseCount('delivery_remittances', 2);
        $this->assertDatabaseCount('transactions', 3);
    }

    public function test_tiny_oversized_and_script_images_are_rejected_without_storing_files(): void
    {
        $this->handoverAndDispatch();
        $tiny = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aSuoAAAAASUVORK5CYII=');
        $this->deliver(['photo' => UploadedFile::fake()->createWithContent('tiny.png', $tiny)])->assertUnprocessable()->assertJsonValidationErrors('photo');
        $large = file_get_contents(base_path('tests/Fixtures/delivery.png')).str_repeat('x', 5 * 1024 * 1024);
        $this->deliver(['photo' => UploadedFile::fake()->createWithContent('large.png', $large)])->assertUnprocessable()->assertJsonValidationErrors('photo');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="240"><script>alert(1)</script></svg>';
        $this->deliver(['photo' => UploadedFile::fake()->createWithContent('script.svg', $svg)])->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->assertFalse($this->job->fresh()->is_completed);
        $this->assertDatabaseCount('delivery_proofs', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_negative_and_fractional_cent_cash_is_rejected(): void
    {
        $this->handoverAndDispatch();
        $this->deliver(['reported_cash' => -1])->assertUnprocessable()->assertJsonValidationErrors('reported_cash');
        $this->deliver(['reported_cash' => '1.001'])->assertUnprocessable()->assertJsonValidationErrors('reported_cash');
        $this->deliver()->assertOk();
        $this->actingAs($this->cashier);
        foreach ([0, -1, '1.001', 101] as $amount) {
            $this->postJson('/api/deliveries/'.$this->job->id.'/remit', ['amount' => $amount, 'submission_key' => (string) Str::uuid()])->assertUnprocessable();
        }
        $this->assertDatabaseCount('delivery_remittances', 0);
        $this->assertEquals(100, $this->buyer->fresh()->debt_balance);
    }

    public function test_a_rider_cannot_reset_the_buyer_code_or_confirm_cashier_handover(): void
    {
        $this->handoverAndDispatch();
        $code = $this->job->fresh()->delivery_code;
        $this->postJson('/api/employee/orders/'.$this->job->id, $this->action('reset_code'))->assertForbidden();
        $this->postJson('/api/employee/orders/'.$this->job->id, $this->action('handover', ['handover_quantity' => 2]))->assertForbidden();
        $this->assertSame($code, $this->job->fresh()->delivery_code);
        $this->rider->employee->update(['is_active' => false]);
        $this->deliver()->assertForbidden();
        $this->assertDatabaseCount('delivery_proofs', 0);
    }

    public function test_a_delivery_cannot_be_completed_before_dispatch(): void
    {
        $this->actingAs($this->cashier)->postJson('/api/fulfillment/'.$this->job->id, $this->action('handover', ['handover_quantity' => 2]))->assertOk();
        $this->deliver()->assertUnprocessable();
        $this->assertEquals('ready', $this->job->fresh()->fulfillment_status);
        $this->assertDatabaseCount('delivery_proofs', 0);
    }

    public function test_buyer_code_guessing_is_rate_limited(): void
    {
        $this->handoverAndDispatch();
        for ($attempt = 0; $attempt < 9; $attempt++) {
            $this->deliver(['recipient_code' => '00000000'])->assertUnprocessable();
        }
        $this->deliver(['recipient_code' => '00000000'])->assertStatus(429);
        $this->assertFalse($this->job->fresh()->is_completed);
        $this->assertDatabaseCount('delivery_proofs', 0);
    }

    public function test_a_failure_after_photo_upload_rolls_back_evidence_and_removes_the_file(): void
    {
        $this->handoverAndDispatch();
        DeliveryProof::creating(fn () => throw new \RuntimeException('Simulated database failure after photo storage'));
        try {
            $this->deliver()->assertStatus(500);
        } finally {
            DeliveryProof::flushEventListeners();
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('delivery_proofs', 0);
        $this->assertDatabaseCount('fulfillment_events', 2);
        $this->assertFalse($this->job->fresh()->is_completed);
        $this->assertEquals(100, $this->buyer->fresh()->debt_balance);
        $this->deliver()->assertOk();
    }
}
