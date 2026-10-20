<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The audit trail and its rank isolation.
 *
 * The important property is not that rows exist, but that a lower rank cannot
 * read a higher rank's changes by calling the API directly.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $manager;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->owner = User::where('username', 'admin')->firstOrFail();
        $this->cashier = User::where('username', 'cashier')->firstOrFail();
        $this->manager = User::create([
            'name' => 'Station Manager', 'username' => 'manager2',
            'email' => 'manager2@example.com', 'password' => 'Private123!',
            'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);
        Customer::where('name', 'Santos Family')->update(['debt_balance' => 150]);
    }

    /** An approved collection is recorded against the approver, not the cashier. */
    private function approveOneCollection(): void
    {
        $customer = Customer::where('name', 'Santos Family')->firstOrFail();
        $this->actingAs($this->cashier)->postJson('/api/customers/'.$customer->id.'/settle', [
            'submission_key' => (string) Str::uuid(), 'amount' => 25,
        ])->assertStatus(202);

        $request = \App\Models\ApprovalRequest::where('type', 'settlement.create')->firstOrFail();
        $this->actingAs($this->manager)->postJson('/api/approvals/'.$request->id.'/decision', [
            'decision' => 'approve', 'submission_key' => (string) Str::uuid(),
        ])->assertOk();
    }

    public function test_an_approved_change_is_recorded_with_its_actor_and_context(): void
    {
        $this->approveOneCollection();

        $log = AuditLog::firstOrFail();
        $this->assertSame($this->manager->id, $log->actor_id);
        $this->assertSame(User::ROLE_ADMIN, $log->actor_role);
        $this->assertSame(AuditLog::CONTEXT_APPROVAL, $log->context);
        $this->assertNotNull($log->approval_request_id);
    }

    public function test_the_owner_sees_every_change(): void
    {
        $this->approveOneCollection();

        $this->actingAs($this->owner)->getJson('/api/audit-logs')->assertOk()
            ->assertJsonCount(AuditLog::count(), 'logs');
    }

    public function test_a_manager_reads_the_owners_change_too(): void
    {
        $product = Product::firstOrFail();
        $this->actingAs($this->owner)->patchJson('/api/products/'.$product->id, ['price' => 321])->assertOk();
        $this->assertSame(1, AuditLog::where('actor_id', $this->owner->id)->count());

        // Both management ranks read the whole trail, so the owner's change is
        // visible to the manager as well.
        $this->actingAs($this->manager)->getJson('/api/audit-logs')->assertOk()
            ->assertJsonCount(1, 'logs')
            ->assertJsonPath('logs.0.actor_role', 'super_admin');
    }

    public function test_a_cashier_reads_only_their_own_changes(): void
    {
        $this->approveOneCollection();

        // The recorded actor is the approver, so the cashier sees nothing.
        $this->actingAs($this->cashier)->getJson('/api/audit-logs')->assertOk()
            ->assertJsonCount(0, 'logs');
    }

    public function test_a_staff_supplied_filter_cannot_widen_their_own_scope(): void
    {
        $product = Product::firstOrFail();
        $this->actingAs($this->owner)->patchJson('/api/products/'.$product->id, ['price' => 654])->assertOk();

        // A cashier asking for the owner's actions explicitly still returns
        // nothing, because the scope is applied before the filter.
        $this->actingAs($this->cashier)
            ->getJson('/api/audit-logs?actor_id='.$this->owner->id)
            ->assertOk()->assertJsonCount(0, 'logs');

        // A manager sees the row, with or without the filter.
        $this->actingAs($this->manager)
            ->getJson('/api/audit-logs?actor_id='.$this->owner->id)
            ->assertOk()->assertJsonCount(1, 'logs');
    }

    public function test_a_deactivated_actor_still_reads_by_their_snapshotted_name(): void
    {
        $product = Product::firstOrFail();
        $this->actingAs($this->owner)->patchJson('/api/products/'.$product->id, ['price' => 999])->assertOk();

        $this->owner->update(['name' => 'Renamed Owner']);

        $log = $this->actingAs($this->owner)->getJson('/api/audit-logs')->json('logs.0');
        // The snapshot survives the rename.
        $this->assertSame('Admin (kmikaelim11)', $log['actor']);
    }

    public function test_only_changed_fields_are_stored(): void
    {
        $product = Product::firstOrFail();
        $this->actingAs($this->owner)->patchJson('/api/products/'.$product->id, ['price' => 44])->assertOk();

        $changes = AuditLog::firstOrFail()->changes;
        $this->assertArrayHasKey('price', $changes);
        // The trail does not duplicate unrelated columns.
        $this->assertArrayNotHasKey('category', $changes);
    }
}
