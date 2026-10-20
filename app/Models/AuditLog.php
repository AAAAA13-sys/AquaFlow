<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded change to sensitive station data.
 *
 * Written inside the same transaction as the change it describes, so a rolled
 * back write never leaves a trail claiming it happened.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /** How a change reached the database. */
    public const CONTEXT_DIRECT = 'direct';

    public const CONTEXT_APPROVAL = 'approval';

    public const CONTEXT_SUBMISSION = 'submission';

    protected $fillable = [
        'actor_id',
        'actor_role',
        'actor_name',
        'action',
        'entity_type',
        'entity_id',
        'entity_label',
        'before',
        'after',
        'changes',
        'context',
        'approval_request_id',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }
}
