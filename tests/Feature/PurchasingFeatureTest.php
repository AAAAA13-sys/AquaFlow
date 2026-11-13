<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchasingFeatureTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $manager;
    private User $cashier;
    private Supplier $supplier;
    private InventoryItem $itemA;
    private InventoryItem $itemB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::where('username', 'admin')->firstOrFail();
        $this->manager = User::where('username', 'manager')->firstOrFail();
        $this->cashier = User::where('username', 'cashier')->firstOrFail();
        $this->supplier = Supplier::query()->orderBy('id')->firstOrFail();

        $this->itemA = $this->makeItem('PO Test Cap', 5, 20, 100);
        $this->itemB = $this->makeItem('PO Test Seal', 50, 10, 60);
    }

    private function makeItem(string $name, int $stock, int $reorder, int $target): InventoryItem
    {
        $item = InventoryItem::create([
            'item_name' => $name, 'category' => 'Consumable', 'stock_on_hand' => $stock, 'unit' => 'pcs',
            'safety_stock' => 0, 'reorder_point' => $reorder, 'target_stock' => $target,
            'supplier_id' => $this->supplier->id,
        ]);

        return $item->refresh();
    }

    private function payload(array $override = []): array
    {
        return array_replace([
            'supplier_id' => $this->supplier->id,
            'expected_date' => '2026-12-01',
            'notes' => 'Weekly restock',
            'lines' => [
                ['inventory_item_id' => $this->itemA->id, 'qty' => 40, 'unit_cost' => 12.5],
                ['inventory_item_id' => $this->itemB->id, 'qty' => 10, 'unit_cost' => 3],
            ],
        ], $override);
    }

    private function createDraft(?User $as = null, array $override = []): array
    {
        $response = $this->actingAs($as ?? $this->owner)->postJson('/api/purchase-orders', $this->payload($override));
        $response->assertCreated();

        return $response->json('purchase_order');
    }

    private function createOrdered(): array
    {
        $po = $this->createDraft();
        $this->actingAs($this->owner)->postJson("/api/purchase-orders/{$po['id']}/order")->assertOk();

        return $this->actingAs($this->owner)->getJson("/api/purchase-orders/{$po['id']}")->json('purchase_order');
    }

    private function receive(array $po, array $qtys, ?string $key = null, ?User $as = null)
    {
        $lines = [];
        foreach ($po['lines'] as $i => $line) {
            $lines[] = ['line_id' => $line['id'], 'qty' => $qtys[$i] ?? 0];
        }

        return $this->actingAs($as ?? $this->owner)->postJson("/api/purchase-orders/{$po['id']}/receive", [
            'submission_key' => $key ?? (string) Str::uuid(), 'lines' => $lines,
        ]);
    }

    public function test_suggestions_list_items_at_or_below_reorder_point(): void
    {
        $response = $this->actingAs($this->manager)->getJson('/api/purchase-orders/suggestions')->assertOk();

        $rows = collect($response->json('suggestions'))->keyBy('inventory_item_id');
        $this->assertTrue($rows->has($this->itemA->id));
        $this->assertFalse($rows->has($this->itemB->id));
        $row = $rows[$this->itemA->id];
        $this->assertSame(95, $row['suggested_qty']);
        $this->assertSame($this->supplier->id, $row['supplier_id']);
        $this->assertSame($this->supplier->name, $row['supplier_name']);
        $this->assertNull($row['last_unit_cost']);

        StockMovement::create(['inventory_item_id' => $this->itemA->id, 'type' => 'restock', 'qty' => 1, 'unit_cost' => 9.75]);
        $again = collect($this->actingAs($this->owner)->getJson('/api/purchase-orders/suggestions')->json('suggestions'))
            ->keyBy('inventory_item_id');
        $this->assertEquals(9.75, $again[$this->itemA->id]['last_unit_cost']);

        $this->itemA->forceFill(['retired_at' => now()])->save();
        $retired = collect($this->actingAs($this->owner)->getJson('/api/purchase-orders/suggestions')->json('suggestions'));
        $this->assertFalse($retired->contains('inventory_item_id', $this->itemA->id));
    }

    public function test_owner_and_manager_can_create_draft_cashier_cannot(): void
    {
        $this->actingAs($this->cashier)->postJson('/api/purchase-orders', $this->payload())->assertForbidden();
        $this->actingAs($this->cashier)->getJson('/api/purchase-orders')->assertForbidden();

        $po = $this->createDraft($this->manager);
        $this->assertSame('draft', $po['status']);
        $this->assertMatchesRegularExpression('/^PO-\d{4}$/', $po['po_number']);
        $this->assertEquals(40 * 12.5 + 10 * 3, $po['total_cost']);
        $this->assertSame($this->manager->id, $po['created_by']['id']);
        $this->assertCount(2, $po['lines']);
        $this->assertSame(40, $po['lines'][0]['qty_remaining']);

        $second = $this->createDraft($this->owner);
        $this->assertNotSame($po['po_number'], $second['po_number']);

        $list = $this->actingAs($this->owner)->getJson('/api/purchase-orders?status=draft')->assertOk();
        $this->assertCount(2, $list->json('purchase_orders'));
        $this->assertCount(0, $this->actingAs($this->owner)->getJson('/api/purchase-orders?status=ordered')->json('purchase_orders'));
    }

    public function test_create_rejects_duplicate_items_and_bad_quantities(): void
    {
        $dup = $this->payload(['lines' => [
            ['inventory_item_id' => $this->itemA->id, 'qty' => 1, 'unit_cost' => 1],
            ['inventory_item_id' => $this->itemA->id, 'qty' => 2, 'unit_cost' => 1],
        ]]);
        $this->actingAs($this->owner)->postJson('/api/purchase-orders', $dup)->assertUnprocessable();

        foreach ([0, -3, 100001] as $qty) {
            $bad = $this->payload(['lines' => [['inventory_item_id' => $this->itemA->id, 'qty' => $qty, 'unit_cost' => 1]]]);
            $this->actingAs($this->owner)->postJson('/api/purchase-orders', $bad)->assertUnprocessable();
        }
        $badCost = $this->payload(['lines' => [['inventory_item_id' => $this->itemA->id, 'qty' => 1, 'unit_cost' => -1]]]);
        $this->actingAs($this->owner)->postJson('/api/purchase-orders', $badCost)->assertUnprocessable();
        $this->actingAs($this->owner)->postJson('/api/purchase-orders', $this->payload(['lines' => []]))->assertUnprocessable();
        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_order_total_overflowing_money_column_is_rejected_on_create_and_update(): void
    {
        $huge = $this->payload(['lines' => [
            ['inventory_item_id' => $this->itemA->id, 'qty' => 100000, 'unit_cost' => 1000000],
        ]]);
        $this->actingAs($this->owner)->postJson('/api/purchase-orders', $huge)
            ->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->assertSame(0, PurchaseOrder::count());

        $po = $this->createDraft();
        $this->actingAs($this->owner)->patchJson("/api/purchase-orders/{$po['id']}", $huge)
            ->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->assertEquals(40 * 12.5 + 10 * 3, PurchaseOrder::find($po['id'])->total_cost);
    }

    public function test_only_draft_can_be_edited(): void
    {
        $po = $this->createDraft();
        $edit = $this->payload(['notes' => 'Changed', 'lines' => [
            ['inventory_item_id' => $this->itemB->id, 'qty' => 7, 'unit_cost' => 2],
        ]]);

        $updated = $this->actingAs($this->manager)->patchJson("/api/purchase-orders/{$po['id']}", $edit)->assertOk();
        $this->assertSame('Changed', $updated->json('purchase_order.notes'));
        $this->assertCount(1, $updated->json('purchase_order.lines'));
        $this->assertEquals(14, $updated->json('purchase_order.total_cost'));

        $this->actingAs($this->owner)->postJson("/api/purchase-orders/{$po['id']}/order")->assertOk();
        $this->actingAs($this->owner)->patchJson("/api/purchase-orders/{$po['id']}", $edit)->assertUnprocessable();
        $this->actingAs($this->owner)->postJson("/api/purchase-orders/{$po['id']}/order")->assertUnprocessable();
    }

    public function test_receive_adds_stock_once_and_retry_returns_same_result(): void
    {
        $po = $this->createOrdered();
        $key = (string) Str::uuid();

        $first = $this->receive($po, [15, 0], $key)->assertOk();
        $this->assertSame(20, $this->itemA->fresh()->stock_on_hand);

        $retry = $this->receive($po, [15, 0], $key)->assertOk();
        $this->assertSame(20, $this->itemA->fresh()->stock_on_hand);
        // JSON object key order is not a response contract (MySQL normalises it).
        $this->assertJsonStringEqualsJsonString($first->getContent(), $retry->getContent());
        $this->assertSame([['line_id' => $po['lines'][0]['id'], 'qty' => 15]], $first->json('received'));
        $this->assertSame(1, StockMovement::where('purchase_order_id', $po['id'])->count());
    }

    public function test_replay_after_fully_received_returns_original_result_without_changing_stock(): void
    {
        $po = $this->createOrdered();
        $key = (string) Str::uuid();

        $first = $this->receive($po, [40, 10], $key)->assertOk();
        $stockA = $this->itemA->fresh()->stock_on_hand;
        $stockB = $this->itemB->fresh()->stock_on_hand;
        $movements = StockMovement::where('purchase_order_id', $po['id'])->count();

        $replay = $this->receive($po, [40, 10], $key)->assertOk();

        $this->assertJsonStringEqualsJsonString($first->getContent(), $replay->getContent());
        $this->assertSame($stockA, $this->itemA->fresh()->stock_on_hand);
        $this->assertSame($stockB, $this->itemB->fresh()->stock_on_hand);
        $this->assertSame($movements, StockMovement::where('purchase_order_id', $po['id'])->count());
    }

    public function test_supplier_with_a_purchase_order_cannot_be_deleted(): void
    {
        $this->createDraft();

        $this->actingAs($this->owner)->deleteJson("/api/suppliers/{$this->supplier->id}")
            ->assertStatus(422)->assertJsonValidationErrors('supplier');

        $this->assertNotNull(Supplier::find($this->supplier->id));
    }

    public function test_inventory_item_on_a_purchase_order_line_cannot_be_deleted(): void
    {
        $this->createDraft();

        $this->actingAs($this->owner)->deleteJson("/api/inventory/{$this->itemA->id}")
            ->assertStatus(422)->assertJsonValidationErrors('inventory');

        $this->assertNotNull(InventoryItem::find($this->itemA->id));
    }

    public function test_same_key_different_payload_is_rejected(): void
    {
        $po = $this->createOrdered();
        $key = (string) Str::uuid();

        $this->receive($po, [10, 0], $key)->assertOk();
        $this->receive($po, [11, 0], $key)->assertUnprocessable();
        $this->assertSame(15, $this->itemA->fresh()->stock_on_hand);
    }

    public function test_partial_receive_then_complete_sets_statuses(): void
    {
        $po = $this->createOrdered();

        $partial = $this->receive($po, [40, 4])->assertOk();
        $this->assertSame('partially_received', $partial->json('purchase_order.status'));
        $this->assertNull($partial->json('purchase_order.received_at'));
        $this->assertSame(6, $partial->json('purchase_order.lines.1.qty_remaining'));

        $done = $this->receive($po, [0, 6])->assertOk();
        $this->assertSame('received', $done->json('purchase_order.status'));
        $this->assertNotNull($done->json('purchase_order.received_at'));
        $this->assertSame(45, $this->itemA->fresh()->stock_on_hand);
        $this->assertSame(60, $this->itemB->fresh()->stock_on_hand);
    }

    public function test_receive_more_than_remaining_is_rejected(): void
    {
        $po = $this->createOrdered();

        $this->receive($po, [41, 0])->assertUnprocessable();
        $this->receive($po, [0, 0])->assertUnprocessable();
        $this->assertSame(5, $this->itemA->fresh()->stock_on_hand);
        $this->assertSame(0, StockMovement::where('purchase_order_id', $po['id'])->count());

        $this->receive($po, [30, 0])->assertOk();
        $this->receive($po, [11, 0])->assertUnprocessable();
    }

    public function test_receive_requires_ordered_status(): void
    {
        $draft = $this->createDraft();
        $this->receive($draft, [1, 0])->assertUnprocessable();

        $cancelled = $this->createDraft();
        $this->actingAs($this->owner)->postJson("/api/purchase-orders/{$cancelled['id']}/cancel", ['reason' => 'Wrong supplier'])->assertOk();
        $this->receive($cancelled, [1, 0])->assertUnprocessable();
        $this->assertSame(5, $this->itemA->fresh()->stock_on_hand);
    }

    public function test_manager_cannot_cancel_ordered_po_owner_can_with_reason(): void
    {
        $po = $this->createOrdered();
        $this->receive($po, [10, 0])->assertOk();

        $this->actingAs($this->manager)->postJson("/api/purchase-orders/{$po['id']}/cancel", ['reason' => 'Changed mind'])->assertForbidden();
        $this->actingAs($this->owner)->postJson("/api/purchase-orders/{$po['id']}/cancel", [])->assertUnprocessable();

        $cancel = $this->actingAs($this->owner)->postJson("/api/purchase-orders/{$po['id']}/cancel", ['reason' => 'Supplier closed'])->assertOk();
        $this->assertSame('cancelled', $cancel->json('purchase_order.status'));
        $this->assertSame('Supplier closed', $cancel->json('purchase_order.cancel_reason'));
        $this->assertSame(15, $this->itemA->fresh()->stock_on_hand, 'Received stock is not reversed.');

        $draft = $this->createDraft($this->manager);
        $this->actingAs($this->owner)->postJson('/api/purchase-orders', $this->payload())->assertCreated();
        $this->actingAs($this->manager)->postJson("/api/purchase-orders/{$draft['id']}/cancel", ['reason' => 'Duplicate draft'])->assertOk();

        $otherDraft = $this->createDraft($this->owner);
        $this->actingAs($this->manager)->postJson("/api/purchase-orders/{$otherDraft['id']}/cancel", ['reason' => 'Not mine'])->assertForbidden();
    }

    public function test_received_po_cannot_be_cancelled(): void
    {
        $po = $this->createOrdered();
        $this->receive($po, [40, 10])->assertOk();

        $this->actingAs($this->owner)->postJson("/api/purchase-orders/{$po['id']}/cancel", ['reason' => 'Too late'])->assertUnprocessable();
        $this->assertSame('received', PurchaseOrder::find($po['id'])->status);
    }

    public function test_every_state_change_is_audit_logged(): void
    {
        $po = $this->createDraft();
        $this->actingAs($this->owner)->patchJson("/api/purchase-orders/{$po['id']}", $this->payload(['notes' => 'x']))->assertOk();
        $this->actingAs($this->owner)->postJson("/api/purchase-orders/{$po['id']}/order")->assertOk();
        $po = $this->actingAs($this->owner)->getJson("/api/purchase-orders/{$po['id']}")->json('purchase_order');
        $this->receive($po, [1, 0])->assertOk();
        $this->actingAs($this->owner)->postJson("/api/purchase-orders/{$po['id']}/cancel", ['reason' => 'Stop order'])->assertOk();

        $actions = AuditLog::where('entity_type', 'purchaseorder')->where('entity_id', (string) $po['id'])
            ->orderBy('id')->pluck('action')->all();
        $this->assertSame(['po_create', 'po_update', 'po_order', 'po_receive', 'po_cancel'], $actions);
    }

    public function test_receive_records_movement_with_po_and_cost_and_updates_supplier_last_delivery(): void
    {
        $this->supplier->forceFill(['last_delivery' => '2020-01-01'])->save();
        $po = $this->createOrdered();
        $this->receive($po, [40, 10])->assertOk();

        $movement = StockMovement::where('purchase_order_id', $po['id'])
            ->where('inventory_item_id', $this->itemA->id)->firstOrFail();
        $this->assertSame('restock', $movement->type);
        $this->assertSame(40, $movement->qty);
        $this->assertEquals(12.5, (float) $movement->unit_cost);
        $this->assertSame($this->supplier->id, $movement->supplier_id);
        $this->assertSame($this->owner->id, $movement->user_id);
        $this->assertSame('Received on '.$po['po_number'], $movement->notes);
        $this->assertSame(today()->toDateString(), $this->supplier->fresh()->last_delivery->toDateString());
    }

    public function test_purchasing_page_renders_for_management_only(): void
    {
        $owner = $this->actingAs($this->owner)->get('/admin/purchasing');
        $owner->assertOk()->assertSee('Purchase orders')->assertSee('js/admin/purchasing.js', false);
        $this->actingAs($this->manager)->get('/admin/purchasing')->assertOk();
        $this->assertContains($this->actingAs($this->cashier)->get('/admin/purchasing')->getStatusCode(), [302, 403]);
    }
}
