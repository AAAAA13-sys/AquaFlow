<?php

namespace App\Services;

use App\Data\CustomerStatementLedger;
use App\Models\Customer;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds a customer's account statement from the transactions ledger.
 *
 * A row moves the account when it is either an account-charged sale
 * (payment_method = Account, not a Debt Payment) or a Debt Payment. Voided rows
 * are left out: VoidService only voids an account sale when nothing was
 * collected after it, and it removes the charge from debt_balance, so a voided
 * sale nets to zero and listing it would only show a charge that never stood.
 *
 * Balances are derived arithmetically from the live customers.debt_balance
 * instead of the stored transactions.balance_after. That column is a snapshot
 * taken when the row was written; it goes stale once an earlier sale is voided,
 * is null on legacy rows, and ignores debt that predates the ledger. Walking
 * back from the current balance always reconciles with it, in whole cents.
 */
class CustomerStatementService
{
    /**
     * @return array<string,mixed>
     */
    public function build(Customer $customer, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $currentCents = $this->cents($customer->debt_balance);

        // Net movement from the start of the period up to now.
        $sinceFrom = $this->net($this->movements($customer)->whereDate('transaction_date', '>=', $from->toDateString()));
        $openingCents = $currentCents - $sinceFrom;

        $ledger = new CustomerStatementLedger(
            customer: $customer,
            from: $from,
            to: $to,
            openingBalanceCents: $openingCents,
            currentBalanceCents: $currentCents,
        );

        $rows = $this->movements($customer)
            ->with('items:id,transaction_id,item_name,quantity')
            ->whereDate('transaction_date', '>=', $from->toDateString())
            ->whereDate('transaction_date', '<=', $to->toDateString())
            ->orderBy('transaction_date')->orderBy('transaction_time')->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $amountCents = $this->cents($row->total_amount);
            $isPayment = $row->order_type === 'Debt Payment';
            $ledger->recordEntry(
                date: $row->transaction_date->toDateString(),
                time: (string) $row->transaction_time,
                receiptNumber: (string) $row->receipt_number,
                description: $this->describe($row),
                amountCents: $amountCents,
                isPayment: $isPayment,
            );
        }

        return $ledger->toArray();
    }

    /** @return Builder<Transaction> */
    private function movements(Customer $customer): Builder
    {
        return Transaction::query()
            ->where('customer_id', $customer->id)
            ->whereNull('voided_at')
            ->where(fn (Builder $q) => $q->where('order_type', 'Debt Payment')->orWhere('payment_method', 'Account'));
    }

    /** Charges minus payments, in cents. */
    private function net(Builder $query): int
    {
        $net = 0;
        foreach ($query->get(['order_type', 'total_amount']) as $row) {
            $amount = $this->cents($row->total_amount);
            $net += $row->order_type === 'Debt Payment' ? -$amount : $amount;
        }

        return $net;
    }

    private function describe(Transaction $row): string
    {
        if ($row->order_type === 'Debt Payment') {
            return 'Payment received';
        }
        $items = $row->items->map(fn ($item): string => $item->quantity.' x '.$item->item_name)->implode(', ');

        return trim($row->order_type.' on account'.($items !== '' ? ': '.$items : ''));
    }

    private function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
