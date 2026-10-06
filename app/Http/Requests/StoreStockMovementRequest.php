<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStockMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'in:restock,adjustment,damage'],
            'qty' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000', $this->input('type') === 'restock' ? 'min:1' : 'max:1000000', $this->input('type') === 'damage' ? 'max:-1' : 'min:-1000000'],
            'supplier_id' => ['required_if:type,restock', 'nullable', 'integer', 'exists:suppliers,id'],
            'lot_number' => ['nullable', 'string', 'max:100'], 'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'reason' => ['required_unless:type,restock', 'nullable', 'in:damage,spoilage,shrinkage,count_correction'],
            'notes' => ['required', 'string', 'max:2000'],
        ];
    }
}
