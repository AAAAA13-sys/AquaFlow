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
            'mins' => (int) $this->elapsed_minutes,
            'items' => $this->items_description,
            'orderType' => $this->order_type,
            'completed' => (bool) $this->is_completed,
            'stages' => ProductionQueueItem::STAGES,
        ];
    }
}
