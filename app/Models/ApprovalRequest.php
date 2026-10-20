<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One maker-checker proposal.
 *
 * A request is a frozen snapshot of what the maker wanted to do. It never
 * touches domain data itself: `ApprovalService` dispatches the applier that
 * `ApprovalTypes` maps to this `type`, and only that applier writes.
 */
class ApprovalRequest extends Model
{
    public const STATE_PENDING = 'pending';

    public const STATE_APPROVED = 'approved';

    public const STATE_REJECTED = 'rejected';

    public const STATE_CANCELLED = 'cancelled';

    /** Every state a reviewer can settle a request into. */
    public const TERMINAL_STATES = [self::STATE_APPROVED, self::STATE_REJECTED, self::STATE_CANCELLED];

    protected $fillable = [
        'type',
        'target_type',
        'target_id',
        'payload',
        'reason',
        'state',
        'requested_by',
        'decided_by',
        'decided_at',
        'decision_note',
        'superseded_by',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'decided_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ApprovalEvent::class)->orderBy('id');
    }

    /** The request that replaced this one, when a maker resubmitted. */
    public function replacement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by');
    }

    public function isPending(): bool
    {
        return $this->state === self::STATE_PENDING;
    }

    public function isTerminal(): bool
    {
        return in_array($this->state, self::TERMINAL_STATES, true);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('state', self::STATE_PENDING);
    }
}
