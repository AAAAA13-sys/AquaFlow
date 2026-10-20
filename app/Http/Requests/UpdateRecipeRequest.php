<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRecipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A manager proposes a recipe change; the owner approves it.
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'consumables' => ['required', 'array', 'min:1', 'max:100'],
            'consumables.*.inventory_item_id' => ['required', 'integer', 'distinct', Rule::exists('inventory', 'id')->whereNull('retired_at')],
            'consumables.*.qty_per_sale' => ['required', 'integer', 'min:1', 'max:1000000'],
            // Optional note carried onto the manager's approval request.
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
