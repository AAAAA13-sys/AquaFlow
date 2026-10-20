<?php

namespace App\Http\Resources;

use App\Models\ApprovalRequest;
use App\Support\ApprovalTypes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One approval request as the reviewing screen needs it.
 *
 * `can_decide` is computed HERE, on the server, from the viewer's current rank
 * and the self-approval rule. The browser mirrors it to choose which buttons to
 * draw, but the decision endpoint re-checks the same conditions - a tampered
 * client gains nothing.
 *
 * @mixin ApprovalRequest
 */
class ApprovalRequestResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $decided = $this->isTerminal();

        return [
            'id' => $this->id,
            'type' => $this->type,
            'label' => ApprovalTypes::exists($this->type) ? ApprovalTypes::label($this->type) : $this->type,
            'state' => $this->state,
            'is_pending' => $this->isPending(),
            'target_type' => $this->target_type,
            'target_id' => $this->target_id,
            'payload' => $this->payload,
            'reason' => $this->reason,
            'requested_by' => [
                'id' => $this->requester?->id,
                'name' => $this->requester?->name,
                'role' => $this->requester?->role,
            ],
            'decided_by' => $this->decider ? [
                'id' => $this->decider->id,
                'name' => $this->decider->name,
                'role' => $this->decider->role,
            ] : null,
            'decision_note' => $this->decision_note,
            'decided_at' => $this->decided_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'version' => $this->version,
            'can_decide' => $viewer !== null
                && $this->isPending()
                && (int) $this->requested_by !== (int) $viewer->id
                && $viewer->is_active
                && ApprovalTypes::canDecide($viewer, $this->type),
            'can_cancel' => $viewer !== null
                && $this->isPending()
                && ((int) $this->requested_by === (int) $viewer->id || ApprovalTypes::canDecide($viewer, $this->type)),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event) => [
                'actor' => $event->actor?->name,
                'actor_role' => $event->actor_role,
                'from_state' => $event->from_state,
                'to_state' => $event->to_state,
                'explanation' => $event->explanation,
                'at' => $event->created_at?->toISOString(),
            ])->all()),
        ];
    }
}
