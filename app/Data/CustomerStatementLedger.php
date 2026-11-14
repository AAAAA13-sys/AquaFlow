<?php

namespace App\Data;

use App\Models\Customer;
use Carbon\CarbonImmutable;

/**
 * Domain ledger aggregating transactions into an account statement.
 *
 * Balances are managed in integer cents to guarantee deterministic arithmetic,
 * then exposed as 2-decimal floats matching the API response contract.
 */
final class CustomerStatementLedger
{
    /** @var list<StatementLine> */
    private array $lines = [];

    private int $runningCents;

    private int $totalChargesCents = 0;

    private int $totalPaymentsCents = 0;

    public function __construct(
        public readonly Customer $customer,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly int $openingBalanceCents,
        public readonly int $currentBalanceCents,
    ) {
        $this->runningCents = $openingBalanceCents;
    }

    public function recordEntry(
        string $date,
        string $time,
        string $receiptNumber,
        string $description,
        int $amountCents,
        bool $isPayment
    ): void {
        if ($isPayment) {
            $this->runningCents -= $amountCents;
            $this->totalPaymentsCents += $amountCents;
            $charge = 0.0;
            $payment = $amountCents / 100;
        } else {
            $this->runningCents += $amountCents;
            $this->totalChargesCents += $amountCents;
            $charge = $amountCents / 100;
            $payment = 0.0;
        }

        $this->lines[] = new StatementLine(
            date: $date,
            time: $time,
            receiptNumber: $receiptNumber,
            description: $description,
            charge: $charge,
            payment: $payment,
            balanceAfter: $this->runningCents / 100,
        );
    }

    public function openingBalance(): float
    {
        return $this->openingBalanceCents / 100;
    }

    public function closingBalance(): float
    {
        return $this->runningCents / 100;
    }

    public function currentBalance(): float
    {
        return $this->currentBalanceCents / 100;
    }

    public function totalCharges(): float
    {
        return $this->totalChargesCents / 100;
    }

    public function totalPayments(): float
    {
        return $this->totalPaymentsCents / 100;
    }

    /**
     * @return list<StatementLine>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'customer' => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'address' => $this->customer->address,
                'contact' => $this->customer->contact,
            ],
            'period' => [
                'from' => $this->from->toDateString(),
                'to' => $this->to->toDateString(),
            ],
            'opening_balance' => $this->openingBalance(),
            'lines' => array_map(fn (StatementLine $l): array => $l->toArray(), $this->lines),
            'totals' => [
                'charges' => $this->totalCharges(),
                'payments' => $this->totalPayments(),
            ],
            'closing_balance' => $this->closingBalance(),
            'current_balance' => $this->currentBalance(),
        ];
    }
}
