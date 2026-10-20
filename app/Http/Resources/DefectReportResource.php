<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\DefectReport
 */
class DefectReportResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quantity' => (int) $this->quantity,
            'reason' => $this->reason,
            'status' => $this->status,
            'notes' => $this->notes,
            'reported_by' => $this->whenLoaded('reporter', fn (): array => [
                'id' => $this->reporter->id,
                'name' => $this->reporter->name,
            ], null),
            'resolved_by' => $this->whenLoaded('resolver', fn (): ?array => $this->resolver === null ? null : [
                'id' => $this->resolver->id,
                'name' => $this->resolver->name,
            ]),
            'inventory_item' => $this->whenLoaded('inventoryItem', fn (): array => [
                'id' => $this->inventoryItem->id,
                'item' => $this->inventoryItem->item_name,
                'unit' => $this->inventoryItem->unit,
                'stock_on_hand' => (int) $this->inventoryItem->stock_on_hand,
            ]),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
