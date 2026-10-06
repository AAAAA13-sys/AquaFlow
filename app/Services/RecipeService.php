<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class RecipeService
{
    public function installDefaults(int $openingStock = 0): void
    {
        if (! Product::query()->exists()) {
            return;
        }
        foreach (['SHRINK_SLIM', 'SHRINK_ROUND', 'CLEAR_COVER'] as $name) {
            InventoryItem::firstOrCreate(['item_name' => $name], ['category' => 'Consumable', 'unit' => 'pcs', 'stock_on_hand' => $openingStock, 'lead_time_days' => 2]);
        }
        foreach (Product::all() as $product) {
            $recipe = [];
            if ($product->inventory_item_id) {
                $recipe[$product->inventory_item_id] = max(1, $product->inventory_units_per_sale);
            }
            if ($product->isRefill()) {
                foreach (['Non-Spill Caps', 'Heat Shrink Seals'] as $name) {
                    $id = InventoryItem::where('item_name', $name)->value('id');
                    if ($id) {
                        $recipe[$id] = ($recipe[$id] ?? 0) + 1;
                    }
                }
            }
            if ($product->isRefill() || $product->isContainer()) {
                $recipe[InventoryItem::where('item_name', $product->container_kind === 'S' ? 'SHRINK_SLIM' : 'SHRINK_ROUND')->value('id')] = $product->container_kind === 'S' ? 3 : 1;
                $recipe[InventoryItem::where('item_name', 'CLEAR_COVER')->value('id')] = 1;
            }
            foreach ($recipe as $id => $qty) {
                DB::table('product_consumables')->insertOrIgnore(['product_id' => $product->id, 'inventory_item_id' => $id, 'qty_per_sale' => $qty, 'created_at' => now(), 'updated_at' => now()]);
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
