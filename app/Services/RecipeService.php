<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * What a product consumes, per unit sold.
 *
 * The rule lives here once and is used by both the installer (to build the
 * starting catalog) and the Till & Prices screen (when the owner adds a product),
 * so a newly added item can never diverge from a seeded one.
 *
 * Checkout and availability are entirely recipe-driven: a product with no
 * consumables cannot be sold, so `ensureConsumables()` reports whether it managed
 * to write anything and callers refuse to publish a product when it did not.
 */
class RecipeService
{
    public const CONSUMABLE_ITEMS = [
        'Clear Cover',
        'Shrink Seal (Circle Top Slim)',
        'Shrink Seal (Circle Back)',
        'Shrink Seal (Circle Nozzle)',
        'Shrink Seal (Circle Top Round)',
    ];

    /**
     * Derive the consumption recipe for a product.
     *
     * @return array<int,int> inventory item id => quantity per unit sold
     */
    public function recipeFor(Product $product): array
    {
        $recipe = [];

        // Whatever stock row the product is linked to, in the amount it sells.
        if ($product->inventory_item_id) {
            $recipe[$product->inventory_item_id] = max(1, (int) $product->inventory_units_per_sale);
        }

        // Shrink wrap and clear covers are per container. A product with no
        // container kind (X) must not consume them - otherwise an owner-created
        // item would silently drain jug supplies.
        if (($product->isRefill() || $product->isContainer()) && in_array($product->container_kind, ['S', 'R'], true)) {
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

        return $recipe;
    }

    /**
     * Write the derived recipe for a product.
     *
     * Idempotent, and replaces existing rows for this product. Returns false
     * when the derivation produced nothing - the caller must not publish a
     * product in that state, because the till would offer a button it cannot sell.
     */
    public function ensureConsumables(Product $product): bool
    {
        $recipe = $this->recipeFor($product);

        DB::table('product_consumables')->where('product_id', $product->id)->delete();

        foreach ($recipe as $id => $qty) {
            DB::table('product_consumables')->insert([
                'product_id' => $product->id,
                'inventory_item_id' => $id,
                'qty_per_sale' => $qty,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $recipe !== [];
    }

    /**
     * Build the starting catalog's recipes. Also makes sure the container supplies
     * the derivation depends on exist at all.
     */
    public function installDefaults(int $openingStock = 0): void
    {
        if (! Product::query()->exists()) {
            return;
        }

        foreach (self::CONSUMABLE_ITEMS as $name) {
            InventoryItem::firstOrCreate(
                ['item_name' => $name],
                ['category' => 'Consumable', 'unit' => 'pcs', 'stock_on_hand' => $openingStock, 'lead_time_days' => 2]
            );
        }

        foreach (Product::all() as $product) {
            $this->ensureConsumables($product);
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
