<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staff report of stock that arrived damaged, leaked or was found defective.
 *
 * The report is evidence: it records who found the loss and who wrote it off,
 * so it is never deleted by a user-removal cascade. Resolving it moves stock
 * and appends a `damage` stock movement, which keeps the ledger balanced.
 */
class DefectReport extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'reported_by',
        'inventory_item_id',
        'quantity',
        'reason',
        'status',
        'resolved_by',
        'resolved_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    /** Newest first, so the pending queue surfaces the freshest report on top. */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /** A report can only be resolved once, while it is still pending. */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
