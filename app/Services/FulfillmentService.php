<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DeliveryProof;
use App\Models\ProductionQueueItem;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class FulfillmentService
{
    public function listing(User $user): array
    {
        $query = ProductionQueueItem::with(['transaction.items', 'transaction.customer', 'deliveryProof.remittances'])
            ->where(fn ($query) => $query->where('is_completed', false)
                ->orWhereDate('delivered_at', today())
                ->orWhereHas('deliveryProof', fn ($proof) => $proof->whereRaw('reported_cash > COALESCE((SELECT SUM(amount) FROM delivery_remittances WHERE delivery_proof_id = delivery_proofs.id), 0)')));
        if ($user->role === 'rider') {
            $query->where('order_type', 'Delivery')->where(fn ($delivery) => $delivery
                ->where('rider_id', $user->id)
                ->orWhere(fn ($available) => $available->whereNull('rider_id')->where('fulfillment_status', 'ready')));
        }
        if ($user->role === 'refiller') {
            $query->where('fulfillment_status', 'preparing');
        }

        return $query->orderBy('created_at')->orderBy('id')->get()->map(fn ($job) => $this->row($job, $user))->all();
    }

    public function quantities(): array
    {
        return DB::table('production_queue as q')->join('transaction_items as i', 'i.transaction_id', '=', 'q.transaction_id')
            ->where('q.order_type', 'Delivery')->where(fn ($query) => $query->where('q.is_completed', false)->orWhereDate('q.delivered_at', today()))
            ->groupBy('q.fulfillment_status')->selectRaw('q.fulfillment_status, SUM(i.quantity) AS quantity')->pluck('quantity', 'fulfillment_status')->all();
    }

    public function row(ProductionQueueItem $job, User $user): array
    {
        $job->loadMissing(['transaction.items', 'transaction.customer']);
        $lines = $job->transaction?->items->map(fn ($line) => ['product' => $line->item_name, 'quantity' => $line->quantity])->all() ?? [];

        $proof = $job->deliveryProof;
        $money = $user->role !== 'refiller';
        $pending = $proof ? round($proof->unremittedCash(), 2) : 0;

        return ['id' => $job->id, 'receipt' => $job->receipt_number, 'customer' => $job->customer_name,
            'status' => $job->fulfillment_status, 'version' => $job->version, 'order_type' => $job->order_type,
            'rider_id' => $job->rider_id,
            'handed_over_at' => $job->handed_over_at?->toISOString(),
            'expected_quantity' => (int) $job->transaction?->items->sum('quantity'),
            'confirmation_code' => $user->canHandleCash() && ! $job->is_completed ? $job->delivery_code : null,
            'collection_limit' => $money ? $this->collectionLimit($job, false) : null,
            'proof' => $proof && $money ? ['id' => $proof->id, 'recipient_name' => $proof->recipient_name,
                'received_quantity' => $proof->received_quantity, 'reported_cash' => $proof->reported_cash,
                'pending_remittance' => $pending, 'collection_note' => $proof->collection_note,
                'submitted_at' => $proof->created_at->toISOString(),
                'photo_url' => ($user->role === 'rider' ? '/api/employee/orders/' : '/api/deliveries/').$job->id.'/proof',
                'remittances' => $proof->remittances->map(fn ($remittance) => ['amount' => (float) $remittance->amount, 'cashier_id' => $remittance->cashier_id, 'transaction_id' => $remittance->transaction_id, 'note' => $remittance->note, 'at' => $remittance->created_at])->all()] : null, 'created_at' => $job->created_at->toISOString(), 'lines' => $lines,
            'description' => $job->items_description,
            'issues' => collect($job->stage_history ?? [])->where('action', 'issue')->pluck('reason')->values()->all(),
            'address' => $user->role === 'rider' ? ($job->transaction?->delivery_address ?: $job->transaction?->customer?->address) : null,
            'contact' => $user->role === 'rider' ? $job->transaction?->customer?->contact : null];
    }

    public function change(int $id, User $user, array $data): array
    {
        $photo = $data['photo'] ?? null;
        unset($data['photo']);
        if ($photo) {
            $data['photo_hash'] = hash_file('sha256', $photo->getRealPath());
        }
        $storedPath = null;
        try {
            return DB::transaction(function () use ($id, $user, $data, $photo, &$storedPath): array {
                // Serialize this actor's retries and all FIFO transitions by the same rider.
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $job = ProductionQueueItem::whereKey($id)->lockForUpdate()->firstOrFail();
                $action = $data['action'];
                abort_unless(
                    ($action === 'ready' && $user->role === 'refiller') ||
                    (in_array($action, ['handover', 'reset_code'], true) && $user->canHandleCash()) ||
                    (in_array($action, ['dispatch', 'issue']) && $user->role === 'rider' && (! $job->rider_id || (int) $job->rider_id === $user->id)) ||
                    ($action === 'deliver' && $user->role === 'rider' && (int) $job->rider_id === $user->id),
                    403
                );
                $hash = hash('sha256', json_encode([$id, $data], JSON_THROW_ON_ERROR));
                $event = DB::table('fulfillment_events')->where('user_id', $user->id)->where('submission_key', $data['submission_key'])->first();
                if ($event) {
                    abort_unless(hash_equals($event->request_hash, $hash), 409, 'This action key belongs to a different request.');

                    return $this->row($job, $user);
                }
                abort_unless($job->version === (int) $data['version'], 409, 'Order changed. Refresh before continuing.');
                abort_if($job->is_completed, 422, 'This order has already been completed.');
                if (in_array($action, ['dispatch', 'deliver', 'issue'], true)) {
                    abort_unless($job->order_type === 'Delivery', 422, 'Only delivery orders can use rider actions.');
                }
                if ($action === 'dispatch') {
                    abort_unless($job->handed_over_at, 422, 'The cashier must confirm the prepared quantity and hand over this order first.');
                    $older = ProductionQueueItem::where('order_type', 'Delivery')->where(function ($query) use ($user) {
                        $query->where('rider_id', $user->id)
                            ->orWhere(fn ($available) => $available->whereNull('rider_id')->where('fulfillment_status', 'ready'));
                    })
                        ->where('is_completed', false)->whereIn('fulfillment_status', ['preparing', 'ready'])
                        ->where(fn ($query) => $query->where('created_at', '<', $job->created_at)
                            ->orWhere(fn ($sameTime) => $sameTime->where('created_at', $job->created_at)->where('id', '<', $job->id)))
                        ->exists();
                    abort_if($older, 422, 'Dispatch the oldest available order first.');
                }
                if ($action === 'handover') {
                    abort_unless($job->order_type === 'Delivery' && $job->fulfillment_status === 'ready' && ! $job->handed_over_at, 422, 'Only ready deliveries can have their handover verified once.');
                    abort_unless((int) $data['handover_quantity'] === (int) $job->transaction?->items->sum('quantity'), 422, 'The handover quantity must match every item on the receipt.');
                    $job->handed_over_by = $user->id;
                    $job->handed_over_at = now();
                    $job->delivery_code ??= (string) random_int(10000000, 99999999);
                } elseif ($action === 'reset_code') {
                    abort_unless($job->order_type === 'Delivery' && $job->handed_over_at, 422, 'Hand over the order before issuing a replacement code.');
                    $job->delivery_code = (string) random_int(10000000, 99999999);
                } elseif ($action === 'issue') {
                    if (empty($data['reason'])) {
                        throw ValidationException::withMessages(['reason' => 'Describe the delivery issue.']);
                    }
                    abort_unless(in_array($job->fulfillment_status, ['ready', 'out_for_delivery'], true), 422, 'Issues can only be reported for a ready or dispatched delivery.');
                    $job->rider_id ??= $user->id;
                } else {
                    $expected = ['ready' => 'preparing', 'dispatch' => 'ready', 'deliver' => 'out_for_delivery'];
                    abort_unless($job->fulfillment_status === $expected[$action], 422, 'This order is not ready for that action.');
                    if ($action === 'ready') {
                        $job->stage = ProductionQueueItem::FINAL_STAGE;
                    }
                    $job->fulfillment_status = ['ready' => 'ready', 'dispatch' => 'out_for_delivery', 'deliver' => 'delivered'][$action];
                    if (in_array($action, ['dispatch', 'issue'], true) && ! $job->rider_id) {
                        $job->rider_id = $user->id;
                    }
                    if ($job->fulfillment_status === 'delivered') {
                        abort_unless($job->handed_over_at && $photo && $job->delivery_code && hash_equals($job->delivery_code, (string) ($data['recipient_code'] ?? '')), 422, 'A delivery photo and the buyer confirmation code are required.');
                        abort_unless((int) $data['received_quantity'] === (int) $job->transaction?->items->sum('quantity'), 422, 'Received quantity must match the receipt. Report an issue for an incomplete delivery.');
                        abort_if(DeliveryProof::where('photo_hash', $data['photo_hash'])->exists(), 422, 'This photo has already been used for another delivery.');
                        $customer = Customer::whereKey($job->transaction->customer_id)->lockForUpdate()->firstOrFail();
                        $job->transaction->setRelation('customer', $customer);
                        $limit = $this->collectionLimit($job);
                        abort_if((int) round($data['reported_cash'] * 100) > (int) round($limit * 100), 422, 'Reported cash exceeds the unreserved amount due for this delivery.');
                        if ($data['reported_cash'] < $limit && empty($data['collection_note'])) {
                            throw ValidationException::withMessages(['collection_note' => 'Explain why the full amount was not collected.']);
                        }
                        $storedPath = Storage::disk('local')->putFile('delivery-proofs', $photo);
                        throw_unless($storedPath, \RuntimeException::class, 'Delivery photo could not be stored. Try again.');
                        DeliveryProof::create(['customer_id' => $job->transaction->customer_id, 'production_queue_id' => $job->id, 'rider_id' => $user->id,
                            'photo_path' => $storedPath, 'photo_hash' => $data['photo_hash'],
                            'recipient_name' => $data['recipient_name'], 'received_quantity' => $data['received_quantity'],
                            'reported_cash' => $data['reported_cash'], 'collection_note' => $data['collection_note'] ?? null, 'created_at' => now()]);
                        $job->is_completed = true;
                        $job->delivered_at = now();

                        // Delivery confirms receipt; only an explicit ledger payment settles debt.
                    }
                }
                $job->version++;
                $history = $job->stage_history ?? [];
                $history[] = ['status' => $job->fulfillment_status, 'action' => $action, 'user_id' => $user->id, 'at' => now()->toISOString(), 'reason' => $data['reason'] ?? null];
                $job->stage_history = $history;
                $job->save();
                DB::table('fulfillment_events')->insert(['production_queue_id' => $job->id, 'user_id' => $user->id, 'submission_key' => $data['submission_key'], 'request_hash' => $hash, 'action' => $action, 'details' => json_encode(['status' => $job->fulfillment_status, 'rider_id' => $job->rider_id, 'reason' => $data['reason'] ?? null]), 'created_at' => now()]);

                return $this->row($job->refresh(), $user);
            });
        } catch (\Throwable $exception) {
            if ($storedPath) {
                Storage::disk('local')->delete($storedPath);
            }
            if ($exception instanceof QueryException && ($exception->errorInfo[1] ?? null) === 1062 && isset($data['photo_hash'])) {
                throw ValidationException::withMessages(['photo' => 'This delivery photo has already been used.']);
            }
            throw $exception;
        }
    }

    private function collectionLimit(ProductionQueueItem $job, bool $lock = true): float
    {
        $sale = $job->transaction;
        if (! $sale || $sale->payment_method !== 'Account' || $sale->payment_status === 'paid') {
            return 0;
        }

        return round(max(0, min($sale->total_amount, $sale->customer->debt_balance - DeliveryProof::pendingForCustomer($sale->customer_id, $lock))), 2);
    }
}
