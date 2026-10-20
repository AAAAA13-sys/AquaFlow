<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A consumable inventory item with dynamic thresholds.
 *
 * safety_stock, reorder_point and target_stock are written by the inventory
 * engine (Phase 4) from forecast demand and supplier lead times.
 */
class InventoryItem extends Model
{
    protected $table = 'inventory';

    /**
     * The descriptive stock fields a maker-checker proposal may carry.
     *
     * Deliberately excludes `stock_on_hand`: a quantity change is a stock
     * movement with its own reason and audit trail, not a silent edit that
     * approval would post. `UpdateInventoryRequest` still allows it on the
     * direct path, where the engine records the movement.
     */
    public const PROPOSABLE_FIELDS = ['item_name', 'category', 'unit', 'lead_time_days', 'supplier_id'];

    protected $fillable = [
        'item_name',
        'category',
        'stock_on_hand',
        'unit',
        'safety_stock',
        'reorder_point',
        'target_stock',
        'lead_time_days',
        'supplier_id',
        'recalculated_at',
    ];

    protected function casts(): array
    {
        return [
            'stock_on_hand' => 'integer',
            'safety_stock' => 'integer',
            'reorder_point' => 'integer',
            'target_stock' => 'integer',
            'lead_time_days' => 'integer',
            'recalculated_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('retired_at');
    }

    public function isPieces(): bool
    {
        return $this->unit === 'pcs';
    }

    /** Critical when at or below safety stock, warning at/below reorder point. */
    public function status(): string
    {
        if ($this->stock_on_hand <= $this->safety_stock) {
            return 'Critical';
        }
        if ($this->stock_on_hand <= $this->reorder_point) {
            return 'Warning';
        }

        return 'OK';
    }

    public function needsReorder(): bool
    {
        return $this->stock_on_hand <= $this->reorder_point;
    }
}
