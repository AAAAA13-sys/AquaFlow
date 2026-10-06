<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    protected $fillable = ['inventory_item_id', 'type', 'qty', 'user_id', 'transaction_id', 'supplier_id', 'lot_number', 'unit_cost', 'reason', 'notes'];

    protected function casts(): array
    {
        return ['qty' => 'integer', 'unit_cost' => 'decimal:4'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
