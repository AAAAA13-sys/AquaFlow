<?php

namespace App\Http\Resources;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transaction
 */
class TransactionResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'no' => $this->receipt_number,
            'date' => $this->transaction_date?->format('Y-m-d'),
            't' => substr((string) $this->transaction_time, 0, 5),
            'cust' => $this->whenLoaded('customer', fn () => $this->customer->name, ''),
            'type' => $this->order_type,
            'gal' => $this->gallons_volume,
            'vatable' => (float) $this->subtotal_amount,
            'vat' => (float) $this->vat_amount,
            'total' => (float) $this->total_amount,
            'pay' => $this->payment_method,
            'cash_tendered' => $this->cash_tendered,
            'cash_change' => $this->cash_change,
            'balance_after' => $this->balance_after,
            'payment_status' => $this->payment_status,
            'delivery_address' => $this->delivery_address,
            'by' => $this->whenLoaded('cashier', fn () => $this->cashier->name, ''),
            'items' => TransactionItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
