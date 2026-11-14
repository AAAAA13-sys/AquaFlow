<?php

namespace App\Data;

/**
 * Validated input for recording an operating expense.
 */
final readonly class ExpenseData
{
    public function __construct(
        public string $spentOn,
        public string $category,
        public float $amount,
        public ?string $payee,
        public ?string $reference,
        public ?string $notes,
        public string $submissionKey,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        $clean = static function (mixed $v): ?string {
            $s = is_string($v) ? trim($v) : null;

            return $s === '' ? null : $s;
        };

        return new self(
            spentOn: (string) $validated['spent_on'],
            category: (string) $validated['category'],
            amount: (float) $validated['amount'],
            payee: $clean($validated['payee'] ?? null),
            reference: $clean($validated['reference'] ?? null),
            notes: $clean($validated['notes'] ?? null),
            submissionKey: (string) $validated['submission_key'],
        );
    }

    /**
     * Deterministic SHA-256 hash identifying this specific expense payload.
     */
    public function computeRequestHash(): string
    {
        return hash('sha256', json_encode([
            $this->spentOn,
            $this->category,
            number_format($this->amount, 2, '.', ''),
            $this->payee,
            $this->reference,
            $this->notes,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    public function toModelAttributes(int $recordedBy, string $requestHash): array
    {
        return [
            'spent_on' => $this->spentOn,
            'category' => $this->category,
            'amount' => $this->amount,
            'payee' => $this->payee,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'recorded_by' => $recordedBy,
            'submission_key' => $this->submissionKey,
            'request_hash' => $requestHash,
        ];
    }
}
