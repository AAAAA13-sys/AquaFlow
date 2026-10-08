<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_filters_search_audit_details_and_keep_items_isolated(): void
    {
        $this->seed();
        $owner = User::where('username', 'admin')->firstOrFail();
        $owner->update(['name' => 'History Operator']);
        $this->actingAs($owner);
        $item = InventoryItem::firstOrFail();
        $supplier = Supplier::firstOrFail();
        $supplier->update(['name' => 'History Supplier']);
        $restock = StockMovement::create(['inventory_item_id' => $item->id, 'user_id' => $owner->id, 'supplier_id' => $supplier->id, 'type' => 'restock', 'qty' => 10, 'notes' => 'Delivery received', 'created_at' => '2026-01-02 10:00:00']);
        $adjustment = StockMovement::create(['inventory_item_id' => $item->id, 'user_id' => $owner->id, 'type' => 'adjustment', 'qty' => -2, 'reason' => 'count_correction', 'notes' => 'Physical count checked', 'created_at' => '2026-01-03 10:00:00']);
        StockMovement::create(['inventory_item_id' => InventoryItem::where('id', '!=', $item->id)->value('id'), 'user_id' => $owner->id, 'type' => 'restock', 'qty' => 99, 'notes' => 'Unrelated delivery']);
        $restock->forceFill(['created_at' => '2026-01-02 10:00:00'])->save();
        $adjustment->forceFill(['created_at' => '2026-01-03 10:00:00'])->save();
        $url = '/api/inventory/'.$item->id.'/movements';
        $this->getJson($url.'?type=restock&from=2026-01-02&to=2026-01-02&search=History%20Supplier')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $restock->id);
        $this->getJson($url.'?type=adjustment&search=count%20correction')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $adjustment->id)->assertJsonPath('data.0.reason', 'count_correction');
        $this->getJson($url.'?from=2026-01-02&to=2026-01-03&search=History%20Operator&order=oldest')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $restock->id);
        $this->getJson($url.'?from=2026-01-03&to=2026-01-02&type=unknown')->assertUnprocessable()->assertJsonValidationErrors(['to', 'type']);
        $this->actingAs(User::where('username', 'cashier')->firstOrFail());
        $this->getJson($url)->assertForbidden();
    }
}
