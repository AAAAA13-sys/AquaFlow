<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Supplier directory; lead times drive the dynamic reorder points.
 */
class Supplier extends Model
{
    protected $fillable = [
        'name',
        'supplied_items',
        'lead_time_days',
        'contact',
        'last_delivery',
    ];

    protected function casts(): array
    {
        return [
            'lead_time_days' => 'integer',
            'last_delivery' => 'date:Y-m-d',
        ];
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class, 'supplier_id');
    }
}
