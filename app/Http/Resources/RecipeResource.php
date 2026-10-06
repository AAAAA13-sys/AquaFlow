<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecipeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['product_id' => $this->product_id, 'inventory_item_id' => (int) $this->inventory_item_id, 'qty_per_sale' => (int) $this->qty_per_sale];
    }
}
