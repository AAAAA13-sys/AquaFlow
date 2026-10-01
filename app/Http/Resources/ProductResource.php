<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        $stock = $this->relationLoaded('inventoryItem')
            ? $this->inventoryItem?->stock_on_hand
            : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => (float) $this->price,
            'group' => $this->category,
            'kind' => $this->container_kind,
            'cat' => $this->category,
            'sold_at_pos' => (bool) $this->sold_at_pos,
            'walk_in_only' => (bool) $this->walk_in_only,
            'inventory_units_per_sale' => (int) $this->inventory_units_per_sale,
            // Remaining stock of the linked inventory row, when there is one.
            'stock' => $stock === null ? null : (int) $stock,
        ];
    }
}
