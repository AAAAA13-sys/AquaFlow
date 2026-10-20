<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only review history for an approval request.
 *
 * Rows are never updated or deleted. The role is snapshotted so a later
 * promotion cannot change who was entitled to decide, and the submission key
 * makes a retried decision idempotent.
 */
class ApprovalEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'approval_request_id',
        'actor_id',
        'actor_role',
        'from_state',
        'to_state',
        'explanation',
        'submission_key',
        'request_hash',
        'result',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
