<?php

namespace App\Data;

/**
 * Value object encapsulating operating cash flow arithmetic and disclosure.
 */
final readonly class CashFlow
{
    public const FORMULA = 'Cash collected (cash sales, debt payments and rider remittances; GCash is not included) minus purchases at order cost (supplier credit not modelled) minus expenses.';

    public function __construct(
        public int $collectionsTotalCents,
        public int $purchasesReceivedCents,
        public int $expenseCents,
    ) {}

    public function valueInCents(): int
    {
        return $this->collectionsTotalCents - $this->purchasesReceivedCents - $this->expenseCents;
    }

    public function formula(): string
    {
        return self::FORMULA;
    }

    public function formattedValue(): string
    {
        return OperatingMargin::formatCents($this->valueInCents());
    }

    /**
     * @return array{value: string, formula: string}
     */
    public function toArray(): array
    {
        return [
            'value' => $this->formattedValue(),
            'formula' => self::FORMULA,
        ];
    }
}
