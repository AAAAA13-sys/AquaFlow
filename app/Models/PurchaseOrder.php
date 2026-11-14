<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

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

    // ---- Domain Methods (OOP State Machine & Invariants) -------------------

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isOrdered(): bool
    {
        return $this->status === self::ORDERED;
    }

    public function isPartiallyReceived(): bool
    {
        return $this->status === self::PARTIAL;
    }

    public function isReceived(): bool
    {
        return $this->status === self::RECEIVED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    public function canBeEdited(): bool
    {
        return $this->isDraft();
    }

    public function canReceiveStock(): bool
    {
        return $this->isOrdered() || $this->isPartiallyReceived();
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, [self::DRAFT, self::ORDERED, self::PARTIAL], true);
    }

    public function canBeCancelledBy(User $user): bool
    {
        if (! $this->canBeCancelled()) {
            return false;
        }

        if ($this->isDraft()) {
            return $user->isSuperAdmin() || (int) $this->created_by === $user->id;
        }

        return $user->isSuperAdmin();
    }

    public function markAsOrdered(): self
    {
        if (! $this->canBeEdited()) {
            throw ValidationException::withMessages(['status' => 'Only a draft purchase order can be marked as ordered.']);
        }

        $this->forceFill([
            'status' => self::ORDERED,
            'ordered_at' => now(),
        ]);

        return $this;
    }

    public function cancel(User $user, string $reason): self
    {
        if (! $this->canBeCancelled()) {
            throw ValidationException::withMessages(['status' => 'This purchase order can no longer be cancelled.']);
        }

        if ($this->isDraft()) {
            abort_unless($user->isSuperAdmin() || (int) $this->created_by === $user->id, 403,
                'Only the creator or the owner can cancel a draft.');
        } else {
            abort_unless($user->isSuperAdmin(), 403, 'Only the owner can cancel an ordered purchase order.');
        }

        $this->forceFill([
            'status' => self::CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => $user->id,
            'cancel_reason' => $reason,
        ]);

        return $this;
    }

    /**
     * Recalculates order total and receiving status from its collection of line items.
     *
     * @param iterable<PurchaseOrderLine> $lines
     */
    public function recalculateFromLines(iterable $lines): self
    {
        $allComplete = true;
        $total = 0.0;

        foreach ($lines as $line) {
            $total += $line->qty_ordered * (float) $line->unit_cost;
            if (! $line->isFullyReceived()) {
                $allComplete = false;
            }
        }

        $this->total_cost = round($total, 2);

        if ($allComplete) {
            $this->status = self::RECEIVED;
            $this->received_at = now();
        } else {
            $this->status = self::PARTIAL;
            $this->received_at = null;
        }

        return $this;
    }

    // ---- Query Scopes -----------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', [self::CANCELLED]);
    }

    public function scopeForStatus(Builder $query, ?string $status): Builder
    {
        return $query->when($status, fn ($q) => $q->where('status', $status));
    }

    /**
     * Snapshot representation for immutable audit logging.
     *
     * @return array<string, mixed>
     */
    public function toAuditSnapshot(): array
    {
        return [
            'po_number' => $this->po_number,
            'status' => $this->status,
            'supplier_id' => $this->supplier_id,
            'expected_date' => $this->expected_date?->format('Y-m-d'),
            'notes' => $this->notes,
            'total_cost' => (float) $this->total_cost,
        ];
    }
}
