<?php

namespace App\Data;

/**
 * Immutable ledger line on a customer account statement.
 */
final readonly class StatementLine
{
    public function __construct(
        public string $date,
        public string $time,
        public string $receiptNumber,
        public string $description,
        public float $charge,
        public float $payment,
        public float $balanceAfter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'time' => $this->time,
            'receipt_number' => $this->receiptNumber,
            'description' => $this->description,
            'charge' => $this->charge,
            'payment' => $this->payment,
            'balance_after' => $this->balanceAfter,
        ];
    }
}
