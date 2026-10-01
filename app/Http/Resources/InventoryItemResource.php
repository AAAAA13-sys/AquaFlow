<?php

namespace App\Http\Resources;

use App\Models\InventoryItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InventoryItem
 */
class InventoryItemResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item' => $this->item_name,
            'cat' => $this->category,
            'on' => (int) $this->stock_on_hand,
            'unit' => $this->unit,
            'ss' => (int) $this->safety_stock,
            'rop' => (int) $this->reorder_point,
            'target' => (int) $this->target_stock,
            'lead' => (int) $this->lead_time_days,
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier?->name ?? '-', '-'),
            'status' => $this->status(),
            'recalculated' => $this->recalculated_at?->toIso8601String(),
        ];
    }
}
