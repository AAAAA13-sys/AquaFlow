<?php

namespace App\Http\Resources;

use App\Models\ProductionQueueItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductionQueueItem
 */
class ProductionQueueItemResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'no' => $this->receipt_number,
            'cust' => $this->customer_name,
            'stage' => (int) $this->stage,
            'mins' => max((int) $this->elapsed_minutes, (int) $this->created_at?->diffInMinutes(now())),
            'stage_history' => $this->stage_history ?? [],
            'items' => $this->items_description,
            'orderType' => $this->order_type,
            'status' => $this->is_completed ? 'delivered' : 'active',
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'completed' => (bool) $this->is_completed,
            'stages' => ProductionQueueItem::STAGES,
        ];
    }
}
