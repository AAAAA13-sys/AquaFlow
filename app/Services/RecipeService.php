<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class RecipeService
{
    public const CONSUMABLE_ITEMS = [
        'Clear Cover',
        'Shrink Seal (Circle Top Slim)',
        'Shrink Seal (Circle Back)',
        'Shrink Seal (Circle Nozzle)',
        'Shrink Seal (Circle Top Round)',
    ];

    public function installDefaults(int $openingStock = 0): void
    {
        if (! Product::query()->exists()) {
            return;
        }
        foreach (self::CONSUMABLE_ITEMS as $name) {
            InventoryItem::firstOrCreate(['item_name' => $name], ['category' => 'Consumable', 'unit' => 'pcs', 'stock_on_hand' => $openingStock, 'lead_time_days' => 2]);
        }
        foreach (Product::all() as $product) {
            $recipe = [];
            if ($product->inventory_item_id) {
                $recipe[$product->inventory_item_id] = max(1, $product->inventory_units_per_sale);
            }
            if ($product->isRefill() || $product->isContainer()) {
                if ($product->container_kind === 'S') {
                    foreach (['Shrink Seal (Circle Top Slim)', 'Shrink Seal (Circle Back)', 'Shrink Seal (Circle Nozzle)'] as $sName) {
                        $sId = InventoryItem::where('item_name', $sName)->value('id');
                        if ($sId) {
                            $recipe[$sId] = 1;
                        }
                    }
                } else {
                    $rId = InventoryItem::where('item_name', 'Shrink Seal (Circle Top Round)')->value('id');
                    if ($rId) {
                        $recipe[$rId] = 1;
                    }
                }
                $coverId = InventoryItem::where('item_name', 'Clear Cover')->value('id');
                if ($coverId) {
                    $recipe[$coverId] = 1;
                }
            }
            DB::table('product_consumables')->where('product_id', $product->id)->delete();
            foreach ($recipe as $id => $qty) {
                DB::table('product_consumables')->insert(
                    ['product_id' => $product->id, 'inventory_item_id' => $id, 'qty_per_sale' => $qty, 'updated_at' => now(), 'created_at' => now()]
                );
            }
        }
    }

    public function replace(Product $product, array $rows): void
    {
        DB::transaction(function () use ($product, $rows): void {
            Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            DB::table('product_consumables')->where('product_id', $product->id)->delete();
            foreach ($rows as $row) {
                DB::table('product_consumables')->insert(['product_id' => $product->id] + $row + ['created_at' => now(), 'updated_at' => now()]);
            }
            app(InventoryEngine::class)->recalculateAll();
        });
    }
}
