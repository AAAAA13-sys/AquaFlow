<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRecipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'consumables' => ['required', 'array', 'min:1', 'max:100'],
            'consumables.*.inventory_item_id' => ['required', 'integer', 'distinct', 'exists:inventory,id'],
            'consumables.*.qty_per_sale' => ['required', 'integer', 'min:1', 'max:1000000'],
        ];
    }
}
