<?php

namespace App\Data;

/**
 * Validated checkout input.
 *
 * Prices and totals are deliberately absent: the server looks them up so a
 * tampered client cannot influence what a sale costs.
 *
 * There is no container-custody input. Under the Zero Station-Owned Jugs rule
 * the station never lends a jug, so nothing is handed out against a liability.
 */
final readonly class CheckoutData
{
    /**
     * @param  list<array{product_id: string, quantity: int}>  $items
     */
    public function __construct(
        public int $customerId,
        public string $orderType,
        public string $paymentMethod,
        public float $cashTendered,
        public array $items,
    ) {
    }

    /**
     * Build from validated request data.
     *
     * @param  array<string,mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        $items = [];
        foreach ($validated['items'] as $item) {
            $items[] = [
                'product_id' => (string) $item['product_id'],
                'quantity' => (int) $item['quantity'],
            ];
        }

        return new self(
            customerId: (int) $validated['customer_id'],
            orderType: (string) ($validated['order_type'] ?? 'Walk-in'),
            paymentMethod: (string) ($validated['payment_method'] ?? 'Cash'),
            cashTendered: (float) ($validated['cash_tendered'] ?? 0),
            items: $items,
        );
    }
}
