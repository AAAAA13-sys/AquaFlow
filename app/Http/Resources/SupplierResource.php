<?php

namespace App\Http\Resources;

use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Supplier
 */
class SupplierResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'item' => $this->supplied_items,
            'lead' => (int) $this->lead_time_days,
            'contact' => $this->contact,
            'last' => $this->last_delivery?->format('Y-m-d') ?? '-',
        ];
    }
}
