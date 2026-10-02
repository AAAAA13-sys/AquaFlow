<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryRequest extends FormRequest
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
            'item_name' => ['required', 'string', 'max:150', 'unique:inventory,item_name'],
            'category' => ['required', Rule::in(['Consumable', 'Filtration', 'Cleaning', 'Asset'])],
            'stock_on_hand' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'unit' => ['sometimes', 'string', 'max:20'],
            'lead_time_days' => ['sometimes', 'integer', 'min:1', 'max:14'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
        ];
    }
}
