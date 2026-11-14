<?php

namespace App\Data;

/**
 * Validated input for receiving items against an ordered Purchase Order.
 */
final readonly class ReceivePurchaseOrderData
{
    /**
     * @param  list<array{line_id: int, qty: int}>  $lines
     */
    public function __construct(
        public string $submissionKey,
        public array $lines,
    ) {}

    /**
     * @param  array{submission_key: string, lines: array<int, array{line_id: int, qty: int}>}  $validated
     */
    public static function fromArray(array $validated): self
    {
        $lines = collect($validated['lines'])
            ->map(fn (array $l): array => ['line_id' => (int) $l['line_id'], 'qty' => (int) $l['qty']])
            ->sortBy('line_id')
            ->values()
            ->all();

        return new self(
            submissionKey: (string) $validated['submission_key'],
            lines: $lines,
        );
    }

    /**
     * Deterministic SHA-256 hash across PO id and requested line quantities.
     */
    public function computeRequestHash(int $purchaseOrderId): string
    {
        return hash('sha256', json_encode([$purchaseOrderId, $this->lines], JSON_THROW_ON_ERROR));
    }
}
