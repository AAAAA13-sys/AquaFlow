<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockMovementService
{
    public function __construct(private readonly InventoryEngine $engine) {}

    public function deleteItem(InventoryItem $item): void
    {
        if (StockMovement::where('inventory_item_id', $item->id)->exists()
            || DB::table('product_consumables')->where('inventory_item_id', $item->id)->exists()) {
            throw ValidationException::withMessages(['inventory' => 'This item has recipes or audit history and cannot be deleted.']);
        }
        $item->delete();
    }

    public function record(InventoryItem $item, array $data, User $user): StockMovement
    {
        return DB::transaction(function () use ($item, $data, $user): StockMovement {
            $locked = InventoryItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $next = $locked->stock_on_hand + $data['qty'];
            if ($next < 0 || $next > 1000000) {
                throw ValidationException::withMessages(['qty' => 'Stock must remain between 0 and 1,000,000.']);
            }
            $locked->stock_on_hand = $next;
            $locked->save();
            $movement = StockMovement::create($data + ['inventory_item_id' => $locked->id, 'user_id' => $user->id]);
            $this->engine->recalculateItem($locked);

            return $movement;
        });
    }

    public function createItem(array $data, User $user): InventoryItem
    {
        return DB::transaction(function () use ($data, $user): InventoryItem {
            $item = InventoryItem::create($data);
            if ($item->stock_on_hand > 0) {
                StockMovement::create(['inventory_item_id' => $item->id, 'type' => 'restock',
                    'qty' => $item->stock_on_hand, 'user_id' => $user->id, 'supplier_id' => $item->supplier_id, 'notes' => 'Opening stock']);
            }
            $this->engine->recalculateItem($item);

            return $item;
        });
    }
}
