<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\ProductionQueueItem;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class VoidService
{
    public function execute(User $actor, array $data): array
    {
        $key = 'void-approval:'.$actor->id;
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429, 'Too many approval attempts. Try again in one minute.');
        $approver = User::where('username', $data['approver_username'])->first();
        if (! $approver || ! $approver->is_active || $approver->is_bootstrap || $approver->must_change_password
            || ! Hash::check($data['approver_password'], $approver->password)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['approver_password' => 'Approval credentials are invalid or unavailable.']);
        }
        unset($data['approver_password'], $data['approver_username']);
        $hash = hash('sha256', json_encode([$data, $approver->id], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $approver, $data, $hash): array {
            $actors = User::whereIn('id', [$actor->id, $approver->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $actors[$actor->id];
            $currentApprover = $actors[$approver->id];
            abort_unless($actor->is_active && $actor->canHandleCash() && ! $actor->is_bootstrap, 403);
            abort_unless($currentApprover->is_active && ! $currentApprover->is_bootstrap
                && ! $currentApprover->must_change_password && hash_equals($currentApprover->password, $approver->password)
                && $currentApprover->canApproveActor($actor), 403, 'A different, higher-ranked manager must approve this action.');
            $existing = DB::table('void_records')->where('requested_by', $actor->id)->where('submission_key', $data['submission_key'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'This void key belongs to another request.');
                return ['id' => $existing->id, 'replayed' => true];
            }
            if (! empty($data['transaction_id'])) {
                $this->reverseSale($actor, $currentApprover, $data);
            }
            $id = DB::table('void_records')->insertGetId([
                'transaction_id' => $data['transaction_id'] ?? null,
                'requested_by' => $actor->id, 'approved_by' => $currentApprover->id,
                'submission_key' => $data['submission_key'], 'request_hash' => $hash,
                'reason' => $data['reason'], 'cart' => isset($data['cart']) ? json_encode($data['cart'], JSON_THROW_ON_ERROR) : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return ['id' => $id, 'replayed' => false];
        });
    }

    private function reverseSale(User $actor, User $approver, array $data): void
    {
        $sale = Transaction::whereKey($data['transaction_id'])->firstOrFail();
        // Refund from the original cashier's same-day drawer. Closed days are immutable.
        abort_unless((int) $sale->cashier_id === $actor->id, 403, 'Only the original cashier can request this void.');
        app(TransactionSubmissionService::class)->assertDrawerAllowsWrite($actor);
        abort_unless($sale->transaction_date->isToday(), 422, 'Only current business-day sales can be voided.');
        // Fulfilment locks its queue before the customer; match that order.
        $job = ProductionQueueItem::where('transaction_id', $sale->id)->lockForUpdate()->first();
        $customer = Customer::whereKey($sale->customer_id)->lockForUpdate()->firstOrFail();
        $sale = Transaction::whereKey($sale->id)->lockForUpdate()->firstOrFail();
        abort_if($sale->voided_at, 409, 'This sale has already been voided.');
        abort_if($sale->order_type === 'Debt Payment', 422, 'Collections cannot be voided as sales.');
        abort_unless($job && ! $job->is_completed && $job->fulfillment_status === 'preparing'
            && $job->stage === 0 && ! $job->handed_over_at, 422, 'Prepared, handed-over or delivered orders require a return review, not a void.');
        if ($sale->payment_method === 'Account') {
            abort_if($sale->payment_status !== 'unpaid'
                || Transaction::where('customer_id', $customer->id)->where('order_type', 'Debt Payment')->where('id', '>', $sale->id)->exists()
                || app(CustomerLedgerService::class)->collectible($customer) < $sale->total_amount,
                422, 'This sale has subsequent or pending collections. Resolve those before a reversal.');
            $customer->debt_balance = round($customer->debt_balance - $sale->total_amount, 2);
        }
        $movements = StockMovement::where('transaction_id', $sale->id)->where('type', 'sale')->get();
        $stock = InventoryItem::whereIn('id', $movements->pluck('inventory_item_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($movements as $movement) {
            $item = $stock->get($movement->inventory_item_id);
            abort_unless($item && $movement->qty < 0, 422, 'The original stock movement cannot be reversed.');
            $quantity = -$movement->qty;
            $item->stock_on_hand += $quantity;
            $item->save();
            StockMovement::create(['inventory_item_id' => $item->id, 'type' => 'adjustment', 'qty' => $quantity,
                'user_id' => $approver->id, 'transaction_id' => $sale->id, 'reason' => 'Approved sale void',
                'notes' => $data['reason']]);
        }
        $sale->forceFill(['voided_at' => now()])->save();
        foreach ($stock as $item) {
            app(InventoryEngine::class)->recalculateItem($item);
        }
        $customer->total_transactions = max(0, $customer->total_transactions - 1);
        $customer->save();
        $history = $job->stage_history ?? [];
        $history[] = ['action' => 'void', 'user_id' => $approver->id, 'reason' => $data['reason'], 'at' => now()->toIso8601String()];
        $job->forceFill(['is_completed' => true, 'fulfillment_status' => 'voided', 'version' => $job->version + 1, 'stage_history' => $history])->save();
    }
}
