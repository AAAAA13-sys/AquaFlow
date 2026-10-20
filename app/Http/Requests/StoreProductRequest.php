<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A manager may propose a new till item; the owner approves it. The
        // owner still writes directly. ApprovalService decides which path runs.
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
            'inventory_item_id' => ['nullable', 'integer', Rule::exists('inventory', 'id')->whereNull('retired_at')],
            'inventory_units_per_sale' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'walk_in_only' => ['sometimes', 'boolean'],
            'sold_at_pos' => ['sometimes', 'boolean'],
            'consumables' => ['sometimes', 'array', 'min:1', 'max:50'],
            'consumables.*.inventory_item_id' => ['required_with:consumables', 'integer', 'distinct', Rule::exists('inventory', 'id')->whereNull('retired_at')],
            'consumables.*.qty_per_sale' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            // Optional note carried onto the manager's approval request.
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
