<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'price' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'category' => ['required', Rule::in([
                Product::CATEGORY_REFILL,
                Product::CATEGORY_CONSUMABLE,
                Product::CATEGORY_CLEANING,
                Product::CATEGORY_CONTAINER,
            ])],
            'container_kind' => ['required', Rule::in(['S', 'R', 'X'])],
            // Which stock row it draws down. Optional: refills consume caps and
            // seals from the standard recipe without a link.
            'inventory_item_id' => ['nullable', 'integer', 'exists:inventory,id'],
            'inventory_units_per_sale' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'walk_in_only' => ['sometimes', 'boolean'],
            'sold_at_pos' => ['sometimes', 'boolean'],
        ];
    }
}
