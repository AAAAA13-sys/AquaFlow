<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * POS product catalog. Primary keys are slugs ('slim', 'newS', ...).
 *
 * `sold_at_pos` decides what the cashier can ring up; `inventory_item_id` links
 * a product to the stock row it consumes; `walk_in_only` marks merchandise that
 * cannot be attached to a delivery order (a walk-in customer takes a brand-new
 * jug with them).
 */
class Product extends Model
{
    public const CATEGORY_REFILL = 'refill';
    public const CATEGORY_CONSUMABLE = 'consumable';
    public const CATEGORY_CLEANING = 'cleaning';
    public const CATEGORY_CONTAINER = 'container';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'name',
        'price',
        'category',
        'container_kind',
        'is_active',
        'sold_at_pos',
        'inventory_item_id',
        'inventory_units_per_sale',
        'walk_in_only',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'is_active' => 'boolean',
            'sold_at_pos' => 'boolean',
            'inventory_units_per_sale' => 'integer',
            'walk_in_only' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class, 'product_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function isRefill(): bool
    {
        return $this->category === self::CATEGORY_REFILL;
    }

    /** A brand-new container sold as merchandise (not a refill, not a loan). */
    public function isContainer(): bool
    {
        return $this->category === self::CATEGORY_CONTAINER;
    }

    /** Only these items appear on the cashier terminal. */
    public function scopeSoldAtPos(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true)->where('sold_at_pos', true);
    }
}
