<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One audited change.
 *
 * `changes` carries only the fields that differ, so the screen can show
 * "price: 35.00 -> 40.00" without exposing the whole row. Customer contact
 * details and balances are never copied wholesale into the trail.
 *
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'actor' => $this->actor_name ?? $this->actor?->name ?? 'Unknown',
            'actor_role' => $this->actor_role,
            'action' => $this->action,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'entity_label' => $this->entity_label,
            'changes' => $this->changes,
            'context' => $this->context,
            'approval_request_id' => $this->approval_request_id,
            'at' => $this->created_at?->toISOString(),
        ];
    }
}
