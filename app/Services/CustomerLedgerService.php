<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DeliveryProof;
use App\Models\DeliveryRemittance;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class CustomerLedgerService
{
    /**
     * The amount that can be collected from a customer right now.
     *
     * Two things hold money that must not be collected again:
     *   - a delivery collection the rider is still carrying (pending proof)
     *   - a counter collection already recorded but not yet approved, which the
     *     caller tracks separately as `settlement_pending_amount`
     *
     * `$reservedForApproval` is the amount the caller is itself about to hold,
     * so a submission that has not yet written its reservation can still check
     * against the balance as it stands.
     */
    public function collectible(Customer $customer, float $reservedForApproval = 0.0): float
    {
        $pending = DeliveryProof::pendingForCustomer($customer->id);
        $held = (float) $customer->settlement_pending_amount - $reservedForApproval;

        return round(max(0, (float) $customer->debt_balance - $pending - $held), 2);
    }

    /**
     * Refuse a collection that does not fit what is actually collectible.
     *
     * Shared by the direct settle path and the approval submission path, so a
     * cashier's proposal is guarded by the same rule as a manager's direct
     * collection - including the pending-delivery reservation.
     */
    public function assertCollectible(Customer $customer, float $amount, float $reservedForApproval = 0.0): void
    {
        if ((int) round($amount * 100) <= (int) round($this->collectible($customer, $reservedForApproval) * 100)) {
            return;
        }

        if (DeliveryProof::pendingForCustomer($customer->id) > 0) {
            throw ValidationException::withMessages([
                'amount' => 'This balance includes a pending delivery collection. Record that collection separately.',
            ]);
        }

        throw ValidationException::withMessages(['amount' => (float) $customer->settlement_pending_amount > 0
            ? 'This balance includes a collection still waiting for approval. Ask a manager to approve it before recording another.'
            : 'Payment cannot exceed the outstanding balance.']);
    }

    public function settle(Customer $customer, float $amount, User $cashier, string $submissionKey, ?int $deliveryProofId = null, ?string $note = null, ?\Carbon\CarbonInterface $receivedAt = null): array
    {
        abort_unless($cashier->canHandleCash(), 403);

        return app(TransactionSubmissionService::class)->execute($cashier, $submissionKey, 'collection', array_filter(['customer_id' => $customer->id, 'amount' => $amount, 'note' => $note, 'delivery_proof_id' => $deliveryProofId, 'received_at' => $receivedAt?->toIso8601String()], fn ($value) => $value !== null), function () use ($customer, $amount, $cashier, $deliveryProofId, $note, $receivedAt): array {
            /** @var Customer $locked */
            $locked = Customer::query()->lockForUpdate()->find($customer->id);

            $proof = $deliveryProofId === null ? null : DeliveryProof::where('customer_id', $locked->id)->lockForUpdate()->findOrFail($deliveryProofId);

            // A remittance against a specific delivery is measured by the cash
            // that delivery's rider is still carrying, not the whole balance.
            $available = $proof
                ? $proof->unremittedCash(true)
                : max(0, $locked->debt_balance - DeliveryProof::pendingForCustomer($locked->id) - (float) $locked->settlement_pending_amount);

            if ((int) round($amount * 100) > (int) round($available * 100)) {
                throw ValidationException::withMessages(['amount' => $proof
                    ? 'Payment exceeds the remaining delivery collection.'
                    : 'This balance includes a pending delivery collection. Record that collection separately.']);
            }
            if ($proof && $amount < $available && empty($note)) {
                throw ValidationException::withMessages(['note' => 'Explain the partial delivery remittance.']);
            }

            if ($locked->debt_balance <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Customer has no outstanding balance.',
                ]);
            }

            if ((int) round($amount * 100) > (int) round($locked->debt_balance * 100)) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment cannot exceed the outstanding balance.',
                ]);
            }
            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Payment amount must be greater than zero.',
                ]);
            }

            $applied = round($amount, 2);
            $locked->debt_balance = round($locked->debt_balance - $applied, 2);
            $locked->save();
            if ($locked->debt_balance <= 0) {
                Transaction::where('customer_id', $locked->id)->where('payment_method', 'Account')->update(['payment_status' => 'paid']);
            }

            // Record the settlement so it appears in the cashier's history.
            $transaction = Transaction::create([
                'receipt_number' => 'OR-'.Transaction::nextReceiptSequence(),
                'customer_id' => $locked->id,
                'cashier_id' => $cashier->id,
                'order_type' => 'Debt Payment',
                'gallons_volume' => '0 gal',
                'subtotal_amount' => 0,
                'vat_amount' => 0,
                'auto_discount' => 0,
                'manual_discount' => 0,
                'total_amount' => $applied,
                'payment_method' => 'Cash',
                'cash_tendered' => $applied,
                'cash_change' => 0,
                'balance_after' => $locked->debt_balance,
                'transaction_date' => ($receivedAt ?? now())->toDateString(),
                'transaction_time' => ($receivedAt ?? now())->toTimeString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($proof) {
                DeliveryRemittance::create(['delivery_proof_id' => $proof->id, 'cashier_id' => $cashier->id,
                    'transaction_id' => $transaction->id, 'amount' => $applied, 'note' => $note, 'created_at' => now()]);
            }

            return ['applied' => $applied, 'customer' => $locked, 'transaction' => $transaction->load(['customer', 'cashier'])];
        }, $receivedAt?->toDateString());
    }
}

