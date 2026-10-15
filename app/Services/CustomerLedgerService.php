<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class CustomerLedgerService
{
    public function settle(Customer $customer, float $amount, User $cashier, string $submissionKey, ?int $deliveryProofId = null, ?string $note = null): array
    {
        abort_unless($cashier->canHandleCash(), 403);

        return app(TransactionSubmissionService::class)->execute($cashier, $submissionKey, 'collection', array_filter(['customer_id' => $customer->id, 'amount' => $amount, 'note' => $note], fn ($value) => $value !== null), function () use ($customer, $amount, $cashier): array {
            /** @var Customer $locked */
            $locked = Customer::query()->lockForUpdate()->find($customer->id);

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
                'transaction_date' => now()->toDateString(),
                'transaction_time' => now()->toTimeString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['applied' => $applied, 'customer' => $locked, 'transaction' => $transaction->load(['customer', 'cashier'])];
        });
    }
}

