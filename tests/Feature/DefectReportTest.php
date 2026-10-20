<?php

namespace Tests\Feature;

use App\Models\DefectReport;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DefectReportTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private User $rider;
    private User $refiller;
    private User $admin;
    private InventoryItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->cashier = User::query()->where('username', 'cashier')->firstOrFail();
        $this->rider = User::query()->where('username', 'rider')->firstOrFail();
        $this->refiller = User::query()->where('username', 'refiller')->firstOrFail();
        $this->admin = User::query()->where('username', 'admin')->firstOrFail();

        $this->item = InventoryItem::query()->where('item_name', 'Clear Cover')->firstOrFail();
    }

    public function test_cashier_can_submit_a_defect_report(): void
    {
        $response = $this->actingAs($this->cashier)->postJson('/api/defects', [
            'inventory_item_id' => $this->item->id,
            'quantity' => 5,
            'reason' => 'Leaking jug',
        ]);

        $response->assertCreated()
            ->assertJsonPath('report.status', 'pending')
            ->assertJsonPath('report.quantity', 5)
            ->assertJsonPath('report.reason', 'Leaking jug')
            ->assertJsonPath('report.inventory_item.item', 'Clear Cover')
            ->assertJsonPath('report.reported_by.name', 'Juan Dela Cruz');

        $this->assertDatabaseHas('defect_reports', [
            'inventory_item_id' => $this->item->id,
            'quantity' => 5,
            'status' => 'pending',
        ]);
    }

    public function test_rider_can_submit_a_defect_report(): void
    {
        $this->actingAs($this->rider)->postJson('/api/defects', [
            'inventory_item_id' => $this->item->id,
            'quantity' => 2,
            'reason' => 'Torn seal',
        ])->assertCreated();
    }

    public function test_refiller_can_submit_a_defect_report(): void
    {
        $this->actingAs($this->refiller)->postJson('/api/defects', [
            'inventory_item_id' => $this->item->id,
            'quantity' => 1,
            'reason' => 'Cracked cap',
        ])->assertCreated();
    }

    public function test_super_admin_can_submit_a_defect_report(): void
    {
        $this->actingAs($this->admin)->postJson('/api/defects', [
            'inventory_item_id' => $this->item->id,
            'quantity' => 3,
            'reason' => 'Broken handle',
        ])->assertCreated();
    }

    public function test_quantity_above_stock_is_rejected(): void
    {
        $this->actingAs($this->cashier)->postJson('/api/defects', [
            'inventory_item_id' => $this->item->id,
            'quantity' => $this->item->stock_on_hand + 1,
            'reason' => 'Leaking jug',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity' => 'Quantity exceeds available stock']);
    }

    public function test_quantity_of_zero_is_rejected(): void
    {
        $this->actingAs($this->cashier)->postJson('/api/defects', [
            'inventory_item_id' => $this->item->id,
            'quantity' => 0,
            'reason' => 'Leaking jug',
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');
    }

    public function test_reason_is_required(): void
    {
        $this->actingAs($this->cashier)->postJson('/api/defects', [
            'inventory_item_id' => $this->item->id,
            'quantity' => 1,
            'reason' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_inventory_item_must_exist(): void
    {
        $this->actingAs($this->cashier)->postJson('/api/defects', [
            'inventory_item_id' => 999999,
            'quantity' => 1,
            'reason' => 'Leaking jug',
        ])->assertUnprocessable()->assertJsonValidationErrors('inventory_item_id');
    }

    public function test_cashier_cannot_view_the_defect_list(): void
    {
        DefectReport::query()->create([
            'reported_by' => $this->rider->id,
            'inventory_item_id' => $this->item->id,
            'quantity' => 1,
            'reason' => 'Torn seal',
            'status' => 'pending',
        ]);

        $this->actingAs($this->cashier)->getJson('/api/defects')->assertForbidden();
    }

    public function test_rider_cannot_view_the_defect_list(): void
    {
        $this->actingAs($this->rider)->getJson('/api/defects')->assertForbidden();
    }

    public function test_admin_can_approve_a_report_and_stock_is_deducted(): void
    {
        $before = $this->item->stock_on_hand;

        $report = DefectReport::query()->create([
            'reported_by' => $this->rider->id,
            'inventory_item_id' => $this->item->id,
            'quantity' => 4,
            'reason' => 'Torn seal',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin)
            ->patchJson('/api/defects/'.$report->id.'/approve', ['notes' => 'Confirmed torn']);

        $response->assertOk()
            ->assertJsonPath('report.status', 'approved')
            ->assertJsonPath('report.resolved_by.name', 'Admin (kmikaelim11)')
            ->assertJsonPath('stock_on_hand', $before - 4);

        $this->item->refresh();
        $this->assertSame($before - 4, $this->item->stock_on_hand);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $this->item->id,
            'type' => 'damage',
            'qty' => -4,
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_admin_can_reject_a_report(): void
    {
        $report = DefectReport::query()->create([
            'reported_by' => $this->rider->id,
            'inventory_item_id' => $this->item->id,
            'quantity' => 2,
            'reason' => 'Torn seal',
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin)
            ->patchJson('/api/defects/'.$report->id.'/reject', ['notes' => 'Not damage - crease'])
            ->assertOk()
            ->assertJsonPath('report.status', 'rejected')
            ->assertJsonPath('report.resolved_by.name', 'Admin (kmikaelim11)');

        $this->item->refresh();
        $this->assertSame($this->item->stock_on_hand, $this->item->stock_on_hand, 'Rejecting must not move stock.');
        $this->assertSame(0, $this->item->stockMovements()->where('type', 'damage')->count());
    }

    public function test_approving_twice_is_rejected(): void
    {
        $report = DefectReport::query()->create([
            'reported_by' => $this->rider->id,
            'inventory_item_id' => $this->item->id,
            'quantity' => 2,
            'reason' => 'Torn seal',
            'status' => 'approved',
            'resolved_by' => $this->admin->id,
            'resolved_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->patchJson('/api/defects/'.$report->id.'/approve')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_admin_cannot_approve_when_stock_has_dropped_below_the_reported_quantity(): void
    {
        // File a report for more than what is currently on hand: the row is
        // locked and re-checked at approval time, so this must not drive stock
        // negative.
        $report = DefectReport::query()->create([
            'reported_by' => $this->rider->id,
            'inventory_item_id' => $this->item->id,
            'quantity' => $this->item->stock_on_hand,
            'reason' => 'Torn seal',
            'status' => 'pending',
        ]);

        $this->item->update(['stock_on_hand' => 1]);

        $this->actingAs($this->admin)
            ->patchJson('/api/defects/'.$report->id.'/approve')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity' => 'Quantity exceeds available stock']);

        $this->item->refresh();
        $this->assertSame(1, $this->item->stock_on_hand, 'Stock must not move on a refused approval.');
    }

    public function test_employee_cannot_approve_or_reject(): void
    {
        $report = DefectReport::query()->create([
            'reported_by' => $this->rider->id,
            'inventory_item_id' => $this->item->id,
            'quantity' => 1,
            'reason' => 'Torn seal',
            'status' => 'pending',
        ]);

        $this->actingAs($this->refiller)
            ->patchJson('/api/defects/'.$report->id.'/approve')
            ->assertForbidden();

        $this->actingAs($this->rider)
            ->patchJson('/api/defects/'.$report->id.'/reject')
            ->assertForbidden();

        $report->refresh();
        $this->assertSame('pending', $report->status);
    }

    public function test_index_filters_by_status(): void
    {
        DefectReport::query()->create([
            'reported_by' => $this->rider->id, 'inventory_item_id' => $this->item->id,
            'quantity' => 1, 'reason' => 'a', 'status' => 'pending',
        ]);
        DefectReport::query()->create([
            'reported_by' => $this->rider->id, 'inventory_item_id' => $this->item->id,
            'quantity' => 1, 'reason' => 'b', 'status' => 'approved',
            'resolved_by' => $this->admin->id, 'resolved_at' => now(),
        ]);

        $pending = $this->actingAs($this->admin)->getJson('/api/defects?status=pending');
        $pending->assertOk();
        $this->assertSame(1, collect($pending->json('reports'))->count());

        $approved = $this->actingAs($this->admin)->getJson('/api/defects?status=approved');
        $approved->assertOk();
        $this->assertSame(1, collect($approved->json('reports'))->count());
    }
}
