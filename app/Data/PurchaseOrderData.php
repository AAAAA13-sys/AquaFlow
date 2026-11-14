<?php

namespace App\Data;

/**
 * Validated input for creating or updating a Purchase Order.
 */
final readonly class PurchaseOrderData
{
    /**
     * @param  list<array{inventory_item_id: int, qty: int, unit_cost: float|int|string}>  $lines
     */
    public function __construct(
        public int $supplierId,
        public ?string $expectedDate,
        public ?string $notes,
        public array $lines,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        $lines = [];
        foreach ($validated['lines'] as $line) {
            $lines[] = [
                'inventory_item_id' => (int) $line['inventory_item_id'],
                'qty' => (int) $line['qty'],
                'unit_cost' => $line['unit_cost'],
            ];
        }

        return new self(
            supplierId: (int) $validated['supplier_id'],
            expectedDate: ! empty($validated['expected_date']) ? (string) $validated['expected_date'] : null,
            notes: isset($validated['notes']) && trim((string) $validated['notes']) !== '' ? trim((string) $validated['notes']) : null,
            lines: $lines,
        );
    }
}
