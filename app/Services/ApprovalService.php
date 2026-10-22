<?php

namespace App\Services;

use App\Models\ApprovalEvent;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Http\Resources\ApprovalRequestResource;
use App\Support\ApprovalTypes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Maker-checker: propose a change, have a senior account decide it.
 *
 * Two entry points:
 *
 *   submit()  - a maker records a proposal. No domain data changes.
 *   decide()  - an approver accepts or rejects it. Only approval writes.
 *
 * Rules that must survive future edits:
 *
 *   - A request can only ever replay an applier registered in ApprovalTypes.
 *     The client sends a `type`, never a description of a change.
 *   - Nobody decides their own request, and nobody decides a request routed
 *     above their rank. Both are checked against the CURRENT role inside the
 *     transaction, so a role change since submission cannot be exploited.
 *   - A retried decision returns the original result and writes nothing twice.
 *     The submission key is unique per actor and carries a request hash.
 *   - Rejection and cancellation make no domain write at all.
 *   - A settlement is re-checked against the live balance under a row lock
 *     before it posts, because the balance may have moved while it waited.
 */
class ApprovalService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly CustomerLedgerService $ledger,
    ) {}

    /**
     * Record a proposal. Nothing outside this table is touched.
     *
     * @param  array<string,mixed>  $payload  the maker's validated field set
     */
    public function submit(User $maker, string $type, array $payload, ?string $reason = null, ?string $targetId = null): ApprovalRequest
    {
        $definition = ApprovalTypes::definition($type);

        abort_unless($maker->is_active, 403, 'This account is inactive.');
        abort_unless(ApprovalTypes::canPropose($maker, $type), 403, 'Your role cannot request this change.');

        // A proposal stores only attributes the applier can actually write.
        // Descriptive fields (a note, an adjustment reason) never travel as
        // proposed values, so approval cannot try to set a column that is not
        // there.
        $payload = ApprovalTypes::sanitizePayload($type, $payload);

        return DB::transaction(function () use ($maker, $type, $payload, $reason, $targetId, $definition): ApprovalRequest {
            // Serialize one maker's submissions, matching the actor-lock
            // convention used by fulfillment and financial submissions.
            $maker = User::whereKey($maker->id)->lockForUpdate()->firstOrFail();
            if ($type === ApprovalTypes::SETTLEMENT_CREATE) {
                app(TransactionSubmissionService::class)->assertDrawerAllowsWrite($maker);
            }

            $request = ApprovalRequest::create([
                'type' => $type,
                'target_type' => $this->targetType($type),
                'target_id' => $targetId,
                'payload' => $payload,
                'reason' => $reason,
                'state' => ApprovalRequest::STATE_PENDING,
                'requested_by' => $maker->id,
            ]);

            $this->writeEvent($request, $maker, null, ApprovalRequest::STATE_PENDING, $reason);

            return $request;
        });
    }

    /**
     * Record a proposal and return the HTTP shape every staging controller
     * sends back. Keeps the 202 contract in one place so products, recipes,
     * inventory and suppliers all answer identically.
     *
     * @param  array<string,mixed>  $payload
     */
    public function stage(User $maker, string $type, array $payload, ?string $reason = null, ?string $targetId = null): JsonResponse
    {
        $request = $this->submit($maker, $type, $payload, $reason, $targetId);

        return response()->json([
            'pending_approval' => true,
            'message' => 'Sent for approval. Nothing changes until an owner approves it.',
            'request' => new ApprovalRequestResource($request->load('requester')),
        ], 202);
    }

    /**
     * Accept or reject a pending request.
     *
     * Returns the settled request. Safe to retry: the same submission key
     * returns the original outcome without applying anything again.
     *
     * @return array{request:ApprovalRequest, replayed:bool}
     */
    public function decide(
        ApprovalRequest $request,
        User $approver,
        string $decision,
        ?string $note,
        string $submissionKey,
    ): array {
        abort_unless(in_array($decision, ['approve', 'reject'], true), 422, 'Unknown decision.');

        return DB::transaction(function () use ($request, $approver, $decision, $note, $submissionKey): array {
            // Lock actors in a deterministic id order. Two reviewers deciding
            // different requests involving each other cannot deadlock.
            $actors = User::whereIn('id', [$request->requested_by, $approver->id])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $approver = $actors->get($approver->id);
            $maker = $actors->get($request->requested_by);
            abort_unless($approver && $approver->is_active, 403, 'This account is inactive.');
            throw_unless($maker, RuntimeException::class, 'The requesting account no longer exists.');

            /** @var ApprovalRequest $request */
            $request = ApprovalRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            $hash = hash('sha256', json_encode([$request->id, $decision, $note], JSON_THROW_ON_ERROR));

            // Idempotency: this actor already decided with this key.
            $existing = ApprovalEvent::where('actor_id', $approver->id)
                ->where('submission_key', $submissionKey)->first();
            if ($existing) {
                abort_unless(hash_equals((string) $existing->request_hash, $hash), 409,
                    'This approval key was already used for a different decision.');
                abort_unless((int) $existing->approval_request_id === (int) $request->id, 409,
                    'This approval key was already used for a different request.');

                return ['request' => $request->refresh(), 'replayed' => true];
            }

            // A terminal request is history. Re-deciding it is a conflict, not
            // a silent overwrite.
            abort_unless($request->isPending(), 409, 'This request has already been decided.');

            // Self-approval is refused on identity, not on role, so a promoted
            // manager cannot approve work they themselves submitted.
            abort_if((int) $request->requested_by === (int) $approver->id, 403, 'You cannot approve your own request.');
            abort_unless($approver->canApproveActor($maker), 403, 'This request requires a higher-ranked approver.');

            // Route check against the approver's CURRENT role.
            abort_unless(
                ApprovalTypes::canDecide($approver, $request->type),
                403,
                'This request must be decided by a higher role.'
            );

            $fromState = $request->state;
            $result = ['state' => null];

            if ($decision === 'approve') {
                // The applier is a fixed method per type - never client-defined.
                $result = $this->apply($request, $maker, $approver);
                $request->state = ApprovalRequest::STATE_APPROVED;
            } else {
                $request->state = ApprovalRequest::STATE_REJECTED;
                // A rejected collection may have cash reserved against the
                // customer. Give it back so the balance reads correctly and the
                // counter can record the collection again.
                $this->releaseRequestHold($request);
            }

            $request->decided_by = $approver->id;
            $request->decided_at = now();
            $request->decision_note = $note;
            $request->version++;
            $request->save();

            if ($request->state === ApprovalRequest::STATE_APPROVED) {
                // The applier already worked out what changed. Carry that detail
                // into the audit row instead of recording a bare "approved", so
                // the trail says what actually changed and not just that a
                // decision happened.
                $this->audit->record(
                    $approver,
                    ApprovalTypes::auditAction($request->type),
                    $this->auditEntity($request, $result),
                    $result['before'] ?? null,
                    $result['after'] ?? null,
                    AuditLog::CONTEXT_APPROVAL,
                    $request,
                );
            }

            $this->writeEvent($request, $approver, $fromState, $request->state, $note, $submissionKey, $hash, $result);

            return ['request' => $request->refresh(), 'replayed' => false];
        });
    }

    /**
     * Cancel a pending request. The maker, or anyone senior enough to decide
     * it, may withdraw it. No domain write happens either way.
     */
    public function cancel(ApprovalRequest $request, User $actor, ?string $note = null): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $actor, $note): ApprovalRequest {
            $actors = User::whereIn('id', [$actor->id, $request->requested_by])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $actors[$actor->id];
            $maker = $actors->get($request->requested_by);
            abort_unless($actor->is_active, 403, 'This account is inactive.');

            /** @var ApprovalRequest $request */
            $request = ApprovalRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            abort_unless($request->isPending(), 409, 'This request has already been decided.');

            $isMaker = (int) $request->requested_by === (int) $actor->id;
            abort_unless(
                $isMaker || ($maker && $actor->canApproveActor($maker) && ApprovalTypes::canDecide($actor, $request->type)),
                403,
                'You cannot cancel this request.'
            );

            $fromState = $request->state;
            $request->state = ApprovalRequest::STATE_CANCELLED;
            $request->decided_by = $actor->id;
            $request->decided_at = now();
            $request->decision_note = $note;
            $request->version++;
            $request->save();

            // Withdrawing a collection gives its reserved cash back.
            $this->releaseRequestHold($request);

            $this->writeEvent($request, $actor, $fromState, $request->state, $note);

            return $request->refresh();
        });
    }

    /** Requests this viewer is allowed to see. */
    public function visibleTo(User $viewer): \Illuminate\Database\Eloquent\Builder
    {
        $query = ApprovalRequest::query()->with(['requester', 'decider']);

        // The owner oversees everything.
        if ($viewer->isSuperAdmin()) {
            return $query;
        }

        // A manager reviews what staff below them raised, and can always see and
        // withdraw their own proposals. They never see another manager's work.
        //
        // One deliberate exception: every debt-collection request is visible to a
        // manager whoever raised or decided it. Money taken at the counter must
        // be accountable to the manager on duty even when the owner is the one
        // who approved it, so this narrow slice is opened up. Nothing else widens:
        // a manager still never sees an owner's product, recipe, stock or
        // supplier changes.
        if ($viewer->isAdmin()) {
            return $query->where(function ($inner) use ($viewer): void {
                $inner->whereIn('requested_by', User::query()
                    ->whereIn('role', User::STAFF_ROLES)->pluck('id'))
                    ->orWhere('requested_by', $viewer->id)
                    ->orWhere('type', ApprovalTypes::SETTLEMENT_CREATE);
            });
        }

        return $query->where('requested_by', $viewer->id);
    }

    /**
     * Apply an approved request using the fixed applier for its type.
     *
     * Each applier re-reads its target under a row lock and re-checks state, so
     * a change made while the request waited is caught here rather than posted
     * on top of stale data.
     *
     * @return array<string,mixed> a snapshot stored on the review event
     */
    private function apply(ApprovalRequest $request, User $maker, User $approver): array
    {
        return match ($request->type) {
            ApprovalTypes::PRODUCT_CREATE => $this->applyProductCreate($request),
            ApprovalTypes::PRODUCT_UPDATE => $this->applyProductUpdate($request),
            ApprovalTypes::PRODUCT_DELETE => $this->applyProductDelete($request),
            ApprovalTypes::RECIPE_UPDATE => $this->applyRecipeUpdate($request),
            ApprovalTypes::INVENTORY_CREATE => $this->applyInventoryCreate($request, $approver),
            ApprovalTypes::INVENTORY_UPDATE => $this->applyInventoryUpdate($request),
            ApprovalTypes::INVENTORY_DELETE => $this->applyInventoryDelete($request),
            ApprovalTypes::SUPPLIER_CREATE => $this->applySupplierCreate($request),
            ApprovalTypes::SUPPLIER_UPDATE => $this->applySupplierUpdate($request),
            ApprovalTypes::SUPPLIER_DELETE => $this->applySupplierDelete($request),
            ApprovalTypes::SETTLEMENT_CREATE => $this->applySettlement($request, $maker),
            default => throw ValidationException::withMessages([
                'type' => 'This request type needs a manual follow-up action and cannot be auto-applied yet.',
            ]),
        };
    }

    /** @return array<string,mixed> */
    private function applyProductUpdate(ApprovalRequest $request): array
    {
        /** @var Product $product */
        $product = Product::whereKey($request->target_id)->lockForUpdate()->firstOrFail();

        $before = $this->snapshot($product, array_keys($request->payload));
        $product->fill($request->payload)->save();

        return $this->appliedResult($product, $before);
    }

    /** @return array<string,mixed> */
    private function applyRecipeUpdate(ApprovalRequest $request): array
    {
        /** @var Product $product */
        $product = Product::whereKey($request->target_id)->lockForUpdate()->firstOrFail();
        $before = ['recipe' => DB::table('product_consumables')->where('product_id', $product->id)
            ->orderBy('inventory_item_id')->pluck('qty_per_sale', 'inventory_item_id')->all()];

        app(RecipeService::class)->replace($product, $request->payload['consumables']);

        return ['entity' => $product->name, 'before' => $before,
            'after' => ['recipe' => DB::table('product_consumables')->where('product_id', $product->id)
                ->orderBy('inventory_item_id')->pluck('qty_per_sale', 'inventory_item_id')->all()]];
    }

    /** @return array<string,mixed> */
    private function applyInventoryUpdate(ApprovalRequest $request): array
    {
        /** @var InventoryItem $item */
        $item = InventoryItem::whereKey($request->target_id)->lockForUpdate()->firstOrFail();
        $before = $this->snapshot($item, array_keys($request->payload));
        $item->fill($request->payload)->save();

        return $this->appliedResult($item, $before);
    }

    /** @return array<string,mixed> */
    private function applySupplierUpdate(ApprovalRequest $request): array
    {
        /** @var Supplier $supplier */
        $supplier = Supplier::whereKey($request->target_id)->lockForUpdate()->firstOrFail();
        $before = $this->snapshot($supplier, array_keys($request->payload));
        $supplier->fill($request->payload)->save();

        return $this->appliedResult($supplier, $before);
    }

    /**
     * Create a till item from an approved proposal.
     *
     * The store logic itself is shared with the owner's direct write via
     * `ProductService`, so the recipe derivation and the "cannot sell nothing"
     * refusal behave identically on both paths.
     *
     * @return array<string,mixed>
     */
    private function applyProductCreate(ApprovalRequest $request): array
    {
        $product = app(ProductService::class)->create($request->payload);

        return ['entity' => $product->name, 'created_id' => $product->id,
            'before' => null, 'after' => $this->snapshot($product, ['name', 'price', 'category'])];
    }

    /**
     * Remove a till item from an approved proposal.
     *
     * The deletion guard is re-checked here rather than at submission time,
     * because the product may have been sold between the request and the
     * decision. A product with recorded sales refuses to delete, so the
     * approval fails loudly instead of destroying evidence.
     *
     * @return array<string,mixed>
     */
    private function applyProductDelete(ApprovalRequest $request): array
    {
        /** @var Product $product */
        $product = Product::whereKey($request->target_id)->lockForUpdate()->firstOrFail();
        $before = $this->snapshot($product, ['name', 'price', 'category']);

        app(ProductService::class)->delete($product);

        return ['entity' => $before['name'] ?? $request->target_id,
            'before' => $before, 'after' => null];
    }

    /** @return array<string,mixed> */
    private function applyInventoryCreate(ApprovalRequest $request, User $approver): array
    {
        // The opening-stock movement is attributed to the person who approved
        // the item, so the stock ledger names a real account.
        $item = app(StockMovementService::class)->createItem($request->payload, $approver);

        return ['entity' => $item->item_name, 'created_id' => $item->id,
            'before' => null, 'after' => $this->snapshot($item, ['item_name', 'unit', 'lead_time_days'])];
    }

    /** @return array<string,mixed> */
    private function applyInventoryDelete(ApprovalRequest $request): array
    {
        /** @var InventoryItem $item */
        $item = InventoryItem::whereKey($request->target_id)->lockForUpdate()->firstOrFail();
        $before = $this->snapshot($item, ['item_name', 'unit', 'lead_time_days']);

        app(StockMovementService::class)->deleteItem($item);

        return ['entity' => $before['item_name'] ?? $request->target_id,
            'before' => $before, 'after' => null];
    }

    /** @return array<string,mixed> */
    private function applySupplierCreate(ApprovalRequest $request): array
    {
        $supplier = app(SupplierService::class)->create($request->payload);

        return ['entity' => $supplier->name, 'created_id' => $supplier->id,
            'before' => null, 'after' => $this->snapshot($supplier, ['name', 'contact', 'lead_time_days'])];
    }

    /** @return array<string,mixed> */
    private function applySupplierDelete(ApprovalRequest $request): array
    {
        /** @var Supplier $supplier */
        $supplier = Supplier::whereKey($request->target_id)->lockForUpdate()->firstOrFail();
        $before = $this->snapshot($supplier, ['name', 'contact', 'lead_time_days']);

        app(SupplierService::class)->delete($supplier);

        return ['entity' => $before['name'] ?? $request->target_id,
            'before' => $before, 'after' => null];
    }

    /**
     * Post an approved debt collection.
     *
     * The balance is re-read under a row lock, because days may have passed and
     * the customer may have paid at the counter in the meantime. If the amount
     * no longer fits, the request is refused with a conflict and nothing posts -
     * the money is recorded as still outstanding rather than double-credited.
     *
     * @return array<string,mixed>
     */
    private function applySettlement(ApprovalRequest $request, User $maker): array
    {
        $customerId = (int) ($request->payload['customer_id'] ?? 0);
        $amount = round((float) ($request->payload['amount'] ?? 0), 2);

        /** @var Customer $customer */
        $customer = Customer::query()->lockForUpdate()->findOrFail($customerId);

        // This request reserved its own amount against the balance at
        // submission time. Release exactly that reservation, then test whether
        // the money still fits what remains collectible. A conflict here posts
        // nothing and leaves the reservation for a reviewer to reconcile.
        $reserved = (float) $customer->settlement_pending_amount;
        $available = round(max(0, (float) $customer->debt_balance - $reserved) + $amount, 2);
        abort_if((int) round($amount * 100) > (int) round($available * 100), 409,
            'This collection no longer fits the outstanding balance. The request was not applied; re-record it.');

        // Drop this request's own hold so the ledger's availability check sees
        // the balance as it stood when the cashier handed the money over.
        $customer->settlement_pending_amount = max(0, round($reserved - $amount, 2));
        if ($customer->settlement_pending_amount <= 0) {
            $customer->settlement_pending_at = null;
        }
        $customer->save();

        try {
            // Post through the existing ledger so the receipt number sequence,
            // debt_balance arithmetic and paid-off marking stay in one place.
            $result = $this->ledger->settle(
                $customer,
                $amount,
                $maker,
                (string) $request->payload['submission_key'],
                receivedAt: $request->created_at,
            );
        } catch (\Throwable $exception) {
            // The ledger refused after the hold was dropped: put the hold back
            // so the cash already in hand stays visible and uncollectable.
            $this->reserveSettlement($customer->refresh(), $amount);
            abort_if($exception instanceof ValidationException, 409,
                'This collection could not be applied to the current balance. Re-record it.');
            throw $exception;
        }

        return ['entity' => $customer->name, 'applied' => $result['applied'],
            'receipt' => $result['transaction']->receipt_number,
            'balance_after' => (float) $customer->refresh()->debt_balance];
    }

    /**
     * Give back the cash a terminal-but-not-approved collection had reserved.
     *
     * Only settlement requests hold money. Everything else is a no-op, so this
     * is safe to call from both the reject and the cancel paths.
     */
    private function releaseRequestHold(ApprovalRequest $request): void
    {
        if ($request->type !== ApprovalTypes::SETTLEMENT_CREATE) {
            return;
        }

        $customerId = (int) ($request->payload['customer_id'] ?? 0);
        $amount = round((float) ($request->payload['amount'] ?? 0), 2);
        if ($customerId === 0 || $amount <= 0) {
            return;
        }

        /** @var Customer|null $customer */
        $customer = Customer::query()->lockForUpdate()->find($customerId);
        if ($customer) {
            $this->releaseSettlement($customer, $amount);
        }
    }

    /**
     * Reserve cash against a customer while its collection waits for approval.
     *
     * Called by the submit path so availability checks subtract money that is
     * physically already held. Without this the counter could collect the same
     * pesos a second time before the manager approves the first request.
     */
    public function reserveSettlement(Customer $customer, float $amount): void
    {
        $customer->settlement_pending_amount = round((float) $customer->settlement_pending_amount + $amount, 2);
        $customer->settlement_pending_at ??= now();
        $customer->save();
    }

    /** Release a reservation when a collection is rejected or withdrawn. */
    public function releaseSettlement(Customer $customer, float $amount): void
    {
        $customer->settlement_pending_amount = max(0, round((float) $customer->settlement_pending_amount - $amount, 2));
        if ($customer->settlement_pending_amount <= 0) {
            $customer->settlement_pending_at = null;
        }
        $customer->save();
    }

    private function writeEvent(
        ApprovalRequest $request,
        User $actor,
        ?string $from,
        string $to,
        ?string $explanation,
        ?string $submissionKey = null,
        ?string $hash = null,
        ?array $result = null,
    ): ApprovalEvent {
        return ApprovalEvent::create([
            'approval_request_id' => $request->id,
            'actor_id' => $actor->id,
            'actor_role' => $actor->role,
            'from_state' => $from,
            'to_state' => $to,
            'explanation' => $explanation,
            'submission_key' => $submissionKey,
            'request_hash' => $hash,
            'result' => $result,
        ]);
    }

    /**
     * @param  list<string>  $keys
     * @return array<string,mixed>
     */
    private function snapshot(Model $entity, array $keys): array
    {
        $before = [];
        foreach ($keys as $key) {
            $before[$key] = $entity->getAttribute($key);
        }

        return $before;
    }

    /**
     * @param  array<string,mixed>  $before
     * @return array<string,mixed>
     */
    private function appliedResult(Model $entity, array $before): array
    {
        $after = $this->snapshot($entity, array_keys($before));

        return ['entity' => $entity->getAttribute('name') ?? $entity->getKey(),
            'before' => $before, 'after' => $after];
    }

    /** The entity an approval's audit row points at. */
    private function auditEntity(ApprovalRequest $request, array $result = []): Model
    {
        return match ($request->type) {
            ApprovalTypes::PRODUCT_UPDATE => Product::query()->findOrFail($request->target_id),
            ApprovalTypes::RECIPE_UPDATE => Product::query()->findOrFail($request->target_id),
            ApprovalTypes::INVENTORY_UPDATE => InventoryItem::query()->findOrFail($request->target_id),
            ApprovalTypes::SUPPLIER_UPDATE => Supplier::query()->findOrFail($request->target_id),
            // A create has no target id at submission time; the applier reports
            // the row it made, so the audit trail can point at the real record.
            ApprovalTypes::PRODUCT_CREATE => Product::query()->find($result['created_id'] ?? '') ?? new Product(),
            ApprovalTypes::INVENTORY_CREATE => InventoryItem::query()->find($result['created_id'] ?? 0) ?? new InventoryItem(),
            ApprovalTypes::SUPPLIER_CREATE => Supplier::query()->find($result['created_id'] ?? 0) ?? new Supplier(),
            // A delete has already removed the row by the time the audit row is
            // written, so the target is rebuilt from the proposal's own snapshot
            // rather than looked up. Nothing here is persisted.
            ApprovalTypes::PRODUCT_DELETE => $this->retiredEntity(new Product(), $request, 'name'),
            ApprovalTypes::INVENTORY_DELETE => $this->retiredEntity(new InventoryItem(), $request, 'item_name'),
            ApprovalTypes::SUPPLIER_DELETE => $this->retiredEntity(new Supplier(), $request, 'name'),
            default => Customer::query()->findOrFail($request->payload['customer_id'] ?? 0),
        };
    }

    /**
     * A stand-in for a row that no longer exists, purely so the audit trail can
     * name what was removed. The caller writes the audit row from the applier's
     * snapshot; this only supplies the label and type.
     */
    private function retiredEntity(Model $entity, ApprovalRequest $request, string $labelKey): Model
    {
        $entity->forceFill([$labelKey => $request->payload[$labelKey] ?? null]);

        return $entity;
    }

    private function targetType(string $type): string
    {
        return match ($type) {
            ApprovalTypes::PRODUCT_CREATE, ApprovalTypes::PRODUCT_UPDATE, ApprovalTypes::PRODUCT_DELETE, ApprovalTypes::RECIPE_UPDATE => 'product',
            ApprovalTypes::INVENTORY_CREATE, ApprovalTypes::INVENTORY_UPDATE, ApprovalTypes::INVENTORY_DELETE => 'inventory',
            ApprovalTypes::SUPPLIER_CREATE, ApprovalTypes::SUPPLIER_UPDATE, ApprovalTypes::SUPPLIER_DELETE => 'supplier',
            ApprovalTypes::SETTLEMENT_CREATE => 'customer',
            default => 'unknown',
        };
    }
}
