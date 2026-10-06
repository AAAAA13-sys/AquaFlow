<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'lead_time_days' => ['sometimes', 'integer', 'min:1', 'max:14'],
            'reason' => ['sometimes', 'in:damage,spoilage,shrinkage,count_correction'],
            'notes' => ['sometimes', 'string', 'max:2000'],
            'direction' => ['sometimes', 'integer', 'in:-1,1'],
            'item_name' => ['sometimes', 'string', 'max:150'],
            'category' => ['sometimes', 'string', 'in:Consumable,Filtration,Cleaning,Asset'],
            'stock_on_hand' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'unit' => ['sometimes', 'string', 'max:20'],
            'supplier_id' => ['sometimes', 'nullable', 'integer', 'exists:suppliers,id'],
        ];
    }
}
