<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The query-change audit trail.
 *
 * Two jobs:
 *
 *   1. `record()` - capture what changed, inside the caller's transaction so a
 *      rolled back write leaves no trail.
 *   2. `query()`  - read the trail back, already narrowed to what the viewer's
 *      rank is allowed to see.
 *
 * The rank filter lives HERE, not in the controller and not in the browser. A
 * manager must not be able to read an owner's changes by asking the API
 * directly, so the scope is applied to the builder before any filter the caller
 * supplies.
 *
 * What a viewer may read:
 *   owner (super admin) - every recorded change
 *   manager (admin)     - every recorded change
 *   anyone else         - their own changes only
 *
 * Both management ranks read the whole trail on purpose. Seeing who approved
 * which request is part of the control, so the manager who is accountable for
 * the station must be able to inspect it just as the owner can. The trail only
 * ever holds catalog, stock, supplier and collection changes - never account
 * credentials, team-access rows or settings - so this exposes no data the
 * manager cannot already act on.
 */
class AuditLogService
{
    /**
     * Record one change.
     *
     * `$before` and `$after` are the differing fields only; pass null for the
     * half that does not exist (a create has no before, a delete has no after).
     *
     * @param  array<string,mixed>|null  $before
     * @param  array<string,mixed>|null  $after
     */
    public function record(
        User $actor,
        string $action,
        Model $entity,
        ?array $before,
        ?array $after,
        string $context = AuditLog::CONTEXT_DIRECT,
        ?ApprovalRequest $request = null,
    ): AuditLog {
        $changes = $this->diff($before, $after);

        return AuditLog::create([
            'actor_id' => $actor->id,
            // Snapshots, so a later role change or rename cannot rewrite history.
            'actor_role' => $actor->role,
            'actor_name' => $actor->name,
            'action' => $action,
            'entity_type' => $this->entityType($entity),
            'entity_id' => (string) $entity->getKey(),
            'entity_label' => $this->label($entity),
            'before' => $before,
            'after' => $after,
            'changes' => $changes,
            'context' => $context,
            'approval_request_id' => $request?->id,
        ]);
    }

    /**
     * The audit trail as this viewer is allowed to read it.
     *
     * Rank isolation is applied first, so no caller-supplied filter can widen
     * the result set.
     *
     * Both management ranks read the WHOLE trail. A manager and the owner each
     * need to see who approved which request - an approval is a control, and a
     * control that only one rank can inspect is not a control. The trail covers
     * only catalog, stock, supplier and collection changes; it never carries
     * account credentials, team-access rows or settings, so opening it to the
     * manager exposes no data the manager cannot already act on.
     *
     * Every other role still reads only their own changes.
     */
    public function query(User $viewer): Builder
    {
        $builder = AuditLog::query()->with('actor');

        // Owner and manager both oversee the full trail.
        if ($viewer->isSuperAdmin() || $viewer->isAdmin()) {
            return $builder;
        }

        return $builder->where('actor_id', $viewer->id);
    }

    /**
     * label => [from, to] for the fields that actually differ.
     *
     * Only changed fields are stored: an update to a price must not copy the
     * customer's whole row into a second table.
     *
     * @param  array<string,mixed>|null  $before
     * @param  array<string,mixed>|null  $after
     * @return array<string,array{from:mixed,to:mixed}>
     */
    private function diff(?array $before, ?array $after): array
    {
        $keys = array_unique(array_merge(array_keys($before ?? []), array_keys($after ?? [])));
        $changes = [];

        foreach ($keys as $key) {
            $from = $before[$key] ?? null;
            $to = $after[$key] ?? null;

            if ($from === $to) {
                continue;
            }

            $changes[$key] = ['from' => $from, 'to' => $to];
        }

        return $changes;
    }

    private function entityType(Model $entity): string
    {
        return strtolower(class_basename($entity));
    }

    /** A display name that survives the row being deleted later. */
    private function label(Model $entity): ?string
    {
        foreach (['name', 'item_name', 'receipt_number'] as $attribute) {
            $value = $entity->getAttribute($attribute);
            if (is_string($value) && $value !== '') {
                return mb_substr($value, 0, 150);
            }
        }

        return null;
    }
}
