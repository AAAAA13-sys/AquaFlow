<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CartAvailabilityService
{
    public function quantities(array $items): array
    {
        $quantities = [];
        foreach ($items as $item) {
            $id = $item['product_id'];
            $quantities[$id] = ($quantities[$id] ?? 0) + $item['quantity'];
            if ($quantities[$id] > 999 || $quantities[$id] < 1) {
                throw ValidationException::withMessages(['items' => 'Each product must have between 1 and 999 units.']);
            }
        }

        return $quantities;
    }

    public function requirements(array $quantities): array
    {
        $requirements = [];
        $recipes = DB::table('product_consumables')->whereIn('product_id', array_keys($quantities))->get()->groupBy('product_id');
        foreach ($quantities as $id => $quantity) {
            if (! isset($recipes[$id]) || $recipes[$id]->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'No consumption recipe configured for '.$id.'.']);
            }
            foreach ($recipes[$id] as $recipe) {
                if ($recipe->qty_per_sale <= 0) {
                    throw ValidationException::withMessages(['items' => 'Invalid consumption recipe for '.$id.'.']);
                }
                $requirements[$recipe->inventory_item_id] = ($requirements[$recipe->inventory_item_id] ?? 0) + $quantity * $recipe->qty_per_sale;
            }
        }

        return $requirements;
    }

    public function quote(array $items): array
    {
        $quantities = $this->quantities($items);
        $products = Product::soldAtPos()->get();
        if (array_diff(array_keys($quantities), $products->pluck('id')->all())) {
            throw ValidationException::withMessages(['items' => 'This product is not sold at the POS.']);
        }
        $required = $this->requirements($quantities);
        $stock = InventoryItem::all()->keyBy('id');
        $shortages = [];
        foreach ($required as $id => $quantity) {
            if (! isset($stock[$id]) || $stock[$id]->stock_on_hand < $quantity) {
                $shortages[] = 'Insufficient stock: '.($stock[$id]->item_name ?? 'missing inventory item').'.';
            }
        }
        $additional = [];
        foreach ($products as $product) {
            $limit = 999 - ($quantities[$product->id] ?? 0);
            try {
                foreach ($this->requirements([$product->id => 1]) as $id => $perUnit) {
                    $limit = min($limit, (int) floor((($stock[$id]->stock_on_hand ?? 0) - ($required[$id] ?? 0)) / $perUnit));
                }
            } catch (ValidationException) {
                $limit = 0;
            }
            $additional[$product->id] = max(0, $limit);
        }

        return ['valid' => $shortages === [], 'additional' => $additional, 'shortages' => $shortages, 'checked_at' => now()->toISOString()];
    }
}
