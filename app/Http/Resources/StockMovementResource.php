<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'inventory_item_id' => $this->inventory_item_id, 'type' => $this->type, 'qty' => $this->qty,
            'transaction_id' => $this->transaction_id, 'user_id' => $this->user_id, 'user' => $this->user?->name ?? 'System',
            'supplier_id' => $this->supplier_id, 'supplier' => $this->supplier?->name, 'lot_number' => $this->lot_number,
            'unit_cost' => $this->unit_cost, 'reason' => $this->reason, 'notes' => $this->notes, 'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
