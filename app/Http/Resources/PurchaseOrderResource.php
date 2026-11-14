<?php

namespace App\Http\Resources;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseOrder
 */
class PurchaseOrderResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'po_number' => $this->po_number,
            'status' => $this->status,
            'supplier' => ['id' => $this->supplier_id, 'name' => $this->supplier?->name],
            'expected_date' => $this->expected_date?->format('Y-m-d'),
            'notes' => $this->notes,
            'total_cost' => round((float) $this->total_cost, 2),
            'ordered_at' => $this->ordered_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
            'created_by' => ['id' => $this->created_by, 'name' => $this->creator?->name],
            'lines' => $this->lines->map(fn (PurchaseOrderLine $line): array => [
                'id' => $line->id,
                'inventory_item_id' => $line->inventory_item_id,
                'item_name' => $line->item?->item_name,
                'unit' => $line->item?->unit,
                'qty_ordered' => (int) $line->qty_ordered,
                'qty_received' => (int) $line->qty_received,
                'qty_remaining' => $line->remainingQuantity(),
                'unit_cost' => round((float) $line->unit_cost, 4),
                'line_total' => $line->lineTotal(),
            ])->values()->all(),
        ];
    }
}
