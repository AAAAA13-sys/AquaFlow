<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class PurchaseOrderLine extends Model
{
    protected $fillable = ['purchase_order_id', 'inventory_item_id', 'qty_ordered', 'qty_received', 'unit_cost'];

    protected function casts(): array
    {
        return ['qty_ordered' => 'integer', 'qty_received' => 'integer', 'unit_cost' => 'decimal:4'];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    // ---- Domain Methods (OOP Invariants & Calculations) -------------------

    public function remainingQuantity(): int
    {
        return max(0, (int) $this->qty_ordered - (int) $this->qty_received);
    }

    public function isFullyReceived(): bool
    {
        return (int) $this->qty_received >= (int) $this->qty_ordered;
    }

    public function lineTotal(): float
    {
        return round((int) $this->qty_ordered * (float) $this->unit_cost, 2);
    }

    public function receiveQuantity(int $qty): self
    {
        if ($qty > $this->remainingQuantity()) {
            throw ValidationException::withMessages(['lines' => 'Cannot receive more than the remaining quantity.']);
        }

        $this->qty_received += $qty;

        return $this;
    }
}
