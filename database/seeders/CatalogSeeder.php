<?php

namespace Database\Seeders;

use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SystemSetting;
use App\Services\InventoryEngine;
use App\Services\RecipeService;
use Illuminate\Database\Seeder;

/**
 * Product catalog, suppliers, consumable inventory and station settings.
 *
 * Order matters: suppliers, then inventory, then products (a product links to
 * the inventory row it consumes).
 */
class CatalogSeeder extends Seeder
{
    private const LEGACY_STOCK_NAMES = [
        'Non-Spill Caps', 'Heat Shrink Seals', '5-Micron Sediment Filter Cartridges',
        'Food-Grade Soap', 'Sanitizing Sponges', '5-Gal Replacement Slim Jugs',
        '5-Gal Replacement Round Jugs', 'SHRINK_SLIM', 'SHRINK_ROUND', 'CLEAR_COVER',
    ];

    public function retireLegacyInventory(): void
    {
        \Illuminate\Support\Facades\DB::transaction(function (): void {
            // Upgrade-safe: stores created before the new catalog lack the
            // replacement rows, so create them with zero opening stock instead
            // of failing on firstOrFail() below.
            InventoryItem::firstOrCreate(
                ['item_name' => '5 Gallon Slim Jug'],
                ['category' => 'Asset', 'unit' => 'units', 'stock_on_hand' => 0, 'lead_time_days' => 4]
            );
            InventoryItem::firstOrCreate(
                ['item_name' => '5 Gallon Round Jug'],
                ['category' => 'Asset', 'unit' => 'units', 'stock_on_hand' => 0, 'lead_time_days' => 4]
            );
            foreach (RecipeService::CONSUMABLE_ITEMS as $name) {
                InventoryItem::firstOrCreate(
                    ['item_name' => $name],
                    ['category' => 'Consumable', 'unit' => 'pcs', 'stock_on_hand' => 0, 'lead_time_days' => 2]
                );
            }
            InventoryItem::query()->whereIn('item_name', self::LEGACY_STOCK_NAMES)
                ->whereNull('retired_at')->update(['retired_at' => now()]);
            foreach (Product::whereIn('id', ['slim', 'round', 'newS', 'newR'])->get() as $product) {
                if (in_array($product->id, ['newS', 'newR'], true)) {
                    $name = $product->id === 'newS' ? '5 Gallon Slim Jug' : '5 Gallon Round Jug';
                    $item = InventoryItem::current()->where('item_name', $name)->first();
                    if ($item) {
                        $product->inventory_item_id = $item->id;
                        $product->save();
                    }
                }
                app(RecipeService::class)->ensureConsumables($product);
            }
        });
    }

    public function run(bool $demo = true): void
    {
        $suppliers = [
            ['id' => 1, 'name' => 'AquaRaw Trading', 'supplied_items' => 'Raw water', 'lead_time_days' => 2, 'contact' => '0917-000-1111', 'last_delivery' => now()->subDays(8)],
            ['id' => 2, 'name' => 'SealPack', 'supplied_items' => 'Seals, caps, gallons', 'lead_time_days' => 3, 'contact' => '0918-000-2222', 'last_delivery' => now()->subDays(10)],
            ['id' => 3, 'name' => 'ChemClean', 'supplied_items' => 'Soap, sponge, filters', 'lead_time_days' => 5, 'contact' => '0919-000-3333', 'last_delivery' => now()->subDays(13)],
        ];

        foreach ($demo ? $suppliers : [] as $supplier) {
            Supplier::query()->updateOrCreate(['id' => $supplier['id']], $supplier);
        }

        // SHRINK_SLIM / SHRINK_ROUND / CLEAR_COVER are recipe ingredients for
        // every till product, not catalogue extras. They MUST be seeded here:
        // the consumable-recipes migration creates them through
        // installDefaults(), which uses firstOrCreate, so a store that already
        // had products ends up with them at zero - and because every product's
        // recipe needs one of the three, whole-cart availability collapses to
        // zero and the entire till greys out. Owning them here (like every other
        // inventory row) means a re-seed repairs that state instead of
        // preserving it. Thresholds are left to InventoryEngine.
        // Opening stock is per item, not one number for everything. A uniform figure
        // makes every row look machine-made, and for consumables it has to sit
        // clear of the engine's own reorder point or a fresh store opens in
        // stockout alarm.
        $stock = app()->environment('testing')
            ? ['default' => 50]
            : ['default' => 900, 'filter' => 60];

        $inventory = [
            ['item_name' => 'Clear Cover', 'category' => 'Consumable', 'stock_on_hand' => $stock['default'], 'unit' => 'pcs', 'lead_time_days' => 2, 'supplier_id' => 2],
            ['item_name' => 'Shrink Seal (Circle Top Slim)', 'category' => 'Consumable', 'stock_on_hand' => $stock['default'], 'unit' => 'pcs', 'lead_time_days' => 2, 'supplier_id' => 2],
            ['item_name' => 'Shrink Seal (Circle Back)', 'category' => 'Consumable', 'stock_on_hand' => $stock['default'], 'unit' => 'pcs', 'lead_time_days' => 2, 'supplier_id' => 2],
            ['item_name' => 'Shrink Seal (Circle Nozzle)', 'category' => 'Consumable', 'stock_on_hand' => $stock['default'], 'unit' => 'pcs', 'lead_time_days' => 2, 'supplier_id' => 2],
            ['item_name' => 'Shrink Seal (Circle Top Round)', 'category' => 'Consumable', 'stock_on_hand' => $stock['default'], 'unit' => 'pcs', 'lead_time_days' => 2, 'supplier_id' => 2],
            ['item_name' => '5 Gallon Slim Jug', 'category' => 'Asset', 'stock_on_hand' => 40, 'unit' => 'units', 'lead_time_days' => 4, 'supplier_id' => 2],
            ['item_name' => '5 Gallon Round Jug', 'category' => 'Asset', 'stock_on_hand' => 30, 'unit' => 'units', 'lead_time_days' => 4, 'supplier_id' => 2],
        ];

        foreach ($inventory as $item) {
            if (! $demo) {
                $item['stock_on_hand'] = 0;
                $item['supplier_id'] = null;
            }
            // safety_stock / reorder_point / target_stock are deliberately left
            // out: InventoryEngine::recalculateAll() below derives them. Seeding
            // a placeholder reorder point equal to the opening stock left every
            // item permanently sitting exactly on its own threshold, so all of
            // them read "REORDER NOW" forever.
            InventoryItem::query()->updateOrCreate(
                ['item_name' => $item['item_name']],
                $item
            );
        }

        // Give the seeded items real thresholds straight away, so a fresh demo
        // store does not sit in permanent stockout alarm until someone thinks
        // to run the forecast refresh by hand.
        app(InventoryEngine::class)->recalculateAll();

        // Zero Station-Owned Jugs: a refill consumes no container (the jug is the
        // customer's own); a brand-new jug is ordinary merchandise that depletes
        // stock and can only be bought over the counter.
        //
        // The till carries exactly four buttons: Slim refill (P35), Round refill
        // (P35), New Slim jug (P250) and New Round jug (P250). Caps, seals and
        // cleaning supplies stay in the catalogue for stock tracking, but they
        // are owner-managed inventory - not cashier line items.
        $products = [
            ['id' => 'slim', 'name' => 'Slim 5-Gal Refill', 'price' => 35.00, 'category' => 'refill', 'container_kind' => 'S', 'pos' => true, 'walk_in_only' => false, 'inventory' => null, 'units' => 1],
            ['id' => 'round', 'name' => 'Round 5-Gal Refill', 'price' => 35.00, 'category' => 'refill', 'container_kind' => 'R', 'pos' => true, 'walk_in_only' => false, 'inventory' => null, 'units' => 1],
            ['id' => 'newS', 'name' => 'New Slim 5-Gal Jug', 'price' => 250.00, 'category' => 'container', 'container_kind' => 'S', 'pos' => true, 'walk_in_only' => false, 'inventory' => '5 Gallon Slim Jug', 'units' => 1],
            ['id' => 'newR', 'name' => 'New Round 5-Gal Jug', 'price' => 250.00, 'category' => 'container', 'container_kind' => 'R', 'pos' => true, 'walk_in_only' => false, 'inventory' => '5 Gallon Round Jug', 'units' => 1],
            ['id' => 'caps', 'name' => 'Non-Spill Caps Pack 50', 'price' => 15.00, 'category' => 'consumable', 'container_kind' => 'X', 'pos' => false, 'walk_in_only' => false, 'inventory' => null, 'units' => 50],
            ['id' => 'seals', 'name' => 'Heat Shrink Seals Pack 100', 'price' => 20.00, 'category' => 'consumable', 'container_kind' => 'X', 'pos' => false, 'walk_in_only' => false, 'inventory' => null, 'units' => 100],
            ['id' => 'soap', 'name' => 'Cleaning Soap & Sponge', 'price' => 10.00, 'category' => 'cleaning', 'container_kind' => 'X', 'pos' => false, 'walk_in_only' => false, 'inventory' => null, 'units' => 1],
        ];

        foreach ($products as $product) {
            $inventoryName = $product['inventory'];

            Product::query()->updateOrCreate(
                ['id' => $product['id']],
                [
                    'name' => $product['name'],
                    'price' => $product['price'],
                    'category' => $product['category'],
                    'container_kind' => $product['container_kind'],
                    'sold_at_pos' => $product['pos'],
                    'walk_in_only' => $product['walk_in_only'],
                    'inventory_units_per_sale' => $product['units'],
                    'is_active' => true,
                    'inventory_item_id' => $inventoryName === null
                        ? null
                        : InventoryItem::query()->where('item_name', $inventoryName)->value('id'),
                ]
            );
        }

        app(RecipeService::class)->installDefaults($demo ? $stock['default'] : 0);
        $this->retireLegacyInventory();

        SystemSetting::put(SystemSetting::KEY_STATION_NAME, 'AquaFlow Station', 'Displayed name of the water station');
        SystemSetting::put(SystemSetting::KEY_RESTOCK_LEAD_DAYS, '3', 'Default days until new supplies arrive');
        if ($demo) {
            SystemSetting::put(SystemSetting::KEY_SUS_TARGET, '81.67 (Grade A)', 'Target System Usability Scale rating');
        }
    }
}
