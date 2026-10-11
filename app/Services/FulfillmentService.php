<?php

namespace App\Services;

use App\Models\ProductionQueueItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FulfillmentService
{
    public function listing(User $user): array
    {
        $query = ProductionQueueItem::with(['transaction.items', 'transaction.customer'])->where('is_completed', false);
        if ($user->role === 'rider') {
            $query->where('order_type', 'Delivery')
                ->where(function ($q) use ($user) {
                    $q->where('rider_id', $user->id)
                      ->orWhereNull('rider_id');
                });
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

        return ['id' => $job->id, 'receipt' => $job->receipt_number, 'customer' => $job->customer_name,
            'status' => $job->fulfillment_status, 'version' => $job->version, 'order_type' => $job->order_type,
            'rider_id' => $job->rider_id, 'created_at' => $job->created_at->toISOString(), 'lines' => $lines,
            'description' => $job->items_description,
            'issues' => collect($job->stage_history ?? [])->where('action', 'issue')->pluck('reason')->values()->all(),
            'address' => $user->role === 'rider' ? ($job->transaction?->delivery_address ?: $job->transaction?->customer?->address) : null,
            'contact' => $user->role === 'rider' ? $job->transaction?->customer?->contact : null];
    }

    public function change(int $id, User $user, array $data): array
    {
        return DB::transaction(function () use ($id, $user, $data): array {
            // Serialize this actor's retries and all FIFO transitions by the same rider.
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $job = ProductionQueueItem::whereKey($id)->lockForUpdate()->firstOrFail();
            $action = $data['action'];
            abort_unless(
                ($action === 'assign' && ($user->isAdmin() || $user->role === 'rider')) ||
                ($action === 'ready' && $user->role === 'refiller') ||
                (in_array($action, ['dispatch', 'deliver', 'issue']) && $user->role === 'rider' && ($job->rider_id === null || (int) $job->rider_id === $user->id)),
                403
            );
            $hash = hash('sha256', json_encode([$id, $data], JSON_THROW_ON_ERROR));
            $event = DB::table('fulfillment_events')->where('user_id', $user->id)->where('submission_key', $data['submission_key'])->first();
            if ($event) {
                abort_unless(hash_equals($event->request_hash, $hash), 409, 'This action key belongs to a different request.');

                return $this->row($job, $user);
            }
            abort_unless($job->version === $data['version'], 409, 'Order changed. Refresh before continuing.');
            if ($action === 'assign') {
                abort_unless($job->order_type === 'Delivery' && in_array($job->fulfillment_status, ['preparing', 'ready']), 422, 'Only undispached deliveries can be assigned.');
                if ($user->role === 'rider') {
                    abort_if($job->rider_id !== null && (int) $job->rider_id !== $user->id, 422, 'This delivery is already assigned to another rider.');
                    $job->rider_id = $user->id;
                } else {
                    $rider = User::whereKey($data['rider_id'] ?? null)->where('role', 'rider')->where('is_active', true)->whereHas('employee', fn ($q) => $q->where('is_active', true))->first();
                    if (! $rider) {
                        throw ValidationException::withMessages(['rider_id' => 'Choose an active rider.']);
                    }
                    $job->rider_id = $rider->id;
                }
            } elseif ($action === 'issue') {
                if (empty($data['reason'])) {
                    throw ValidationException::withMessages(['reason' => 'Describe the delivery issue.']);
                }
                abort_if($job->is_completed, 422);
                if ($job->rider_id === null && $user->role === 'rider') {
                    $job->rider_id = $user->id;
                }
            } else {
                $expected = ['ready' => 'preparing', 'dispatch' => 'ready', 'deliver' => 'out_for_delivery'];
                abort_unless($job->fulfillment_status === $expected[$action], 422, 'This order is not ready for that action.');
                if ($action === 'dispatch' && $job->rider_id === null && $user->role === 'rider') {
                    $job->rider_id = $user->id;
                }
                $job->fulfillment_status = ['ready' => 'ready', 'dispatch' => 'out_for_delivery', 'deliver' => 'delivered'][$action];
                if ($job->fulfillment_status === 'delivered') {
                    $job->is_completed = true;
                    $job->delivered_at = now();

                    // Clear customer debt and record payment in cashier ledger if paid through delivery rider
                    $tx = $job->transaction;
                    if ($tx && $tx->payment_method === 'Account' && $tx->customer_id) {
                        $cust = \App\Models\Customer::lockForUpdate()->find($tx->customer_id);
                        if ($cust) {
                            $amount = (float) $tx->total_amount;
                            $cust->debt_balance = round(max(0.0, $cust->debt_balance - $amount), 2);
                            $cust->save();

                            $tx->payment_status = 'paid';
                            $tx->save();

                            \App\Models\Transaction::create([
                                'receipt_number' => 'OR-'.\App\Models\Transaction::nextReceiptSequence(),
                                'customer_id' => $cust->id,
                                'cashier_id' => $user->id,
                                'order_type' => 'Debt Payment',
                                'gallons_volume' => '0 gal',
                                'subtotal_amount' => 0,
                                'vat_amount' => 0,
                                'auto_discount' => 0,
                                'manual_discount' => 0,
                                'total_amount' => $amount,
                                'payment_method' => 'Cash',
                                'cash_tendered' => $amount,
                                'cash_change' => 0,
                                'balance_after' => $cust->debt_balance,
                                'delivery_address' => 'Collected upon delivery by rider: '.$user->name,
                                'transaction_date' => now()->toDateString(),
                                'transaction_time' => now()->toTimeString(),
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }
                }
            }
            $job->version++;
            $history = $job->stage_history ?? [];
            $history[] = ['status' => $job->fulfillment_status, 'action' => $action, 'user_id' => $user->id, 'at' => now()->toISOString(), 'reason' => $data['reason'] ?? null];
            $job->stage_history = $history;
            $job->save();
            DB::table('fulfillment_events')->insert(['production_queue_id' => $job->id, 'user_id' => $user->id, 'submission_key' => $data['submission_key'], 'request_hash' => $hash, 'action' => $action, 'details' => json_encode(['status' => $job->fulfillment_status, 'rider_id' => $job->rider_id, 'reason' => $data['reason'] ?? null]), 'created_at' => now()]);

            return $this->row($job, $user);
        });
    }
}
