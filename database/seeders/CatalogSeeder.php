<?php

namespace Database\Seeders;

use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

/**
 * Product catalog, suppliers, consumable inventory and station settings.
 *
 * Order matters: suppliers, then inventory, then products (a product links to
 * the inventory row it consumes).
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $suppliers = [
            ['id' => 1, 'name' => 'AquaRaw Trading', 'supplied_items' => 'Raw water', 'lead_time_days' => 2, 'contact' => '0917-000-1111', 'last_delivery' => now()->subDays(8)],
            ['id' => 2, 'name' => 'SealPack', 'supplied_items' => 'Seals, caps, gallons', 'lead_time_days' => 3, 'contact' => '0918-000-2222', 'last_delivery' => now()->subDays(10)],
            ['id' => 3, 'name' => 'ChemClean', 'supplied_items' => 'Soap, sponge, filters', 'lead_time_days' => 5, 'contact' => '0919-000-3333', 'last_delivery' => now()->subDays(13)],
        ];

        foreach ($suppliers as $supplier) {
            Supplier::query()->updateOrCreate(['id' => $supplier['id']], $supplier);
        }

        $inventory = [
            ['item_name' => 'Non-Spill Caps', 'category' => 'Consumable', 'stock_on_hand' => 450, 'unit' => 'pcs', 'safety_stock' => 150, 'reorder_point' => 600, 'lead_time_days' => 2, 'supplier_id' => 2],
            ['item_name' => 'Heat Shrink Seals', 'category' => 'Consumable', 'stock_on_hand' => 2100, 'unit' => 'pcs', 'safety_stock' => 300, 'reorder_point' => 1200, 'lead_time_days' => 2, 'supplier_id' => 2],
            ['item_name' => '5-Micron Sediment Filter Cartridges', 'category' => 'Filtration', 'stock_on_hand' => 2, 'unit' => 'units', 'safety_stock' => 1, 'reorder_point' => 2, 'lead_time_days' => 3, 'supplier_id' => 3],
            ['item_name' => 'Food-Grade Soap', 'category' => 'Cleaning', 'stock_on_hand' => 18, 'unit' => 'btls', 'safety_stock' => 5, 'reorder_point' => 10, 'lead_time_days' => 1, 'supplier_id' => 3],
            ['item_name' => 'Sanitizing Sponges', 'category' => 'Cleaning', 'stock_on_hand' => 24, 'unit' => 'pcs', 'safety_stock' => 5, 'reorder_point' => 15, 'lead_time_days' => 1, 'supplier_id' => 3],
            ['item_name' => '5-Gal Replacement Slim Jugs', 'category' => 'Asset', 'stock_on_hand' => 40, 'unit' => 'units', 'safety_stock' => 10, 'reorder_point' => 25, 'lead_time_days' => 4, 'supplier_id' => 2],
            ['item_name' => '5-Gal Replacement Round Jugs', 'category' => 'Asset', 'stock_on_hand' => 35, 'unit' => 'units', 'safety_stock' => 10, 'reorder_point' => 25, 'lead_time_days' => 4, 'supplier_id' => 2],
        ];

        foreach ($inventory as $item) {
            InventoryItem::query()->updateOrCreate(
                ['item_name' => $item['item_name']],
                $item + ['target_stock' => 0]
            );
        }

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
            ['id' => 'newS', 'name' => 'New Slim 5-Gal Jug', 'price' => 250.00, 'category' => 'container', 'container_kind' => 'S', 'pos' => true, 'walk_in_only' => true, 'inventory' => '5-Gal Replacement Slim Jugs', 'units' => 1],
            ['id' => 'newR', 'name' => 'New Round 5-Gal Jug', 'price' => 250.00, 'category' => 'container', 'container_kind' => 'R', 'pos' => true, 'walk_in_only' => true, 'inventory' => '5-Gal Replacement Round Jugs', 'units' => 1],
            ['id' => 'caps', 'name' => 'Non-Spill Caps Pack 50', 'price' => 15.00, 'category' => 'consumable', 'container_kind' => 'X', 'pos' => false, 'walk_in_only' => false, 'inventory' => 'Non-Spill Caps', 'units' => 50],
            ['id' => 'seals', 'name' => 'Heat Shrink Seals Pack 100', 'price' => 20.00, 'category' => 'consumable', 'container_kind' => 'X', 'pos' => false, 'walk_in_only' => false, 'inventory' => 'Heat Shrink Seals', 'units' => 100],
            ['id' => 'soap', 'name' => 'Cleaning Soap & Sponge', 'price' => 10.00, 'category' => 'cleaning', 'container_kind' => 'X', 'pos' => false, 'walk_in_only' => false, 'inventory' => 'Food-Grade Soap', 'units' => 1],
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

        app(\App\Services\RecipeService::class)->installDefaults(6000);

        SystemSetting::put(SystemSetting::KEY_STATION_NAME, 'AquaFlow Station', 'Displayed name of the water station');
        SystemSetting::put(SystemSetting::KEY_RESTOCK_LEAD_DAYS, '3', 'Default days until new supplies arrive');
        SystemSetting::put(SystemSetting::KEY_SUS_TARGET, '81.67 (Grade A)', 'Target System Usability Scale rating');
    }
}
