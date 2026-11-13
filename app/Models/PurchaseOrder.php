<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    public const DRAFT = 'draft';
    public const ORDERED = 'ordered';
    public const PARTIAL = 'partially_received';
    public const RECEIVED = 'received';
    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'po_number', 'supplier_id', 'status', 'expected_date', 'notes', 'total_cost', 'created_by',
        'ordered_at', 'received_at', 'cancelled_at', 'cancelled_by', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'expected_date' => 'date:Y-m-d',
            'total_cost' => 'decimal:2',
            'ordered_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('id');
    }
}
