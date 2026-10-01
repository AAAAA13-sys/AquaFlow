<?php

namespace App\Http\Resources;

use App\Models\TransactionItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TransactionItem
 */
class TransactionItemResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'product_id' => $this->product_id,
            'name' => $this->item_name,
            'quantity' => (int) $this->quantity,
            'unit_price' => (float) $this->unit_price,
            'line_total' => (float) $this->line_total,
        ];
    }
}
