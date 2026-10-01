<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'order_type' => ['required', 'in:Walk-in,Delivery'],
            'payment_method' => ['required', 'in:Cash,GCash,Account'],
            // Only meaningful for cash; GCash and account settle exactly.
            'cash_tendered' => ['sometimes', 'numeric', 'min:0', 'max:1000000'],

            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'string', 'max:30'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ];
    }
}
