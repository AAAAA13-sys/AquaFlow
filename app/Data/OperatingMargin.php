<?php

namespace App\Data;

/**
 * Value object encapsulating operating margin arithmetic and disclosure.
 */
final readonly class OperatingMargin
{
    public const FORMULA = 'Net sales (without VAT) minus the cost of supplies consumed minus expenses.';

    public function __construct(
        public int $netSalesCents,
        public int $costOfSuppliesCents,
        public int $expenseCents,
    ) {}

    public function valueInCents(): int
    {
        return $this->netSalesCents - $this->costOfSuppliesCents - $this->expenseCents;
    }

    public function formula(): string
    {
        return self::FORMULA;
    }

    public function formattedValue(): string
    {
        return self::formatCents($this->valueInCents());
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

    public static function formatCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
