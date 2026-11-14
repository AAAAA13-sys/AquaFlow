<?php

namespace App\Services;

use App\Data\CustomerStatementLedger;
use App\Data\FinancialPeriod;
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
    public function build(Customer $customer, CarbonImmutable|FinancialPeriod $from, ?CarbonImmutable $to = null): array
    {
        if ($from instanceof FinancialPeriod) {
            $to = $from->to;
            $from = $from->from;
        }

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
            $ledger->recordEntry(
                date: $row->transaction_date->toDateString(),
                time: (string) $row->transaction_time,
                receiptNumber: (string) $row->receipt_number,
                description: $row->describeForStatement(),
                amountCents: $amountCents,
                isPayment: $row->isDebtPayment(),
            );
        }

        return $ledger->toArray();
    }

    /** @return Builder<Transaction> */
    private function movements(Customer $customer): Builder
    {
        return Transaction::query()->customerStatementMovements($customer);
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
        return $row->describeForStatement();
    }

    private function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
