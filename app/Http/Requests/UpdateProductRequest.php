<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Edits to an existing till item.
 *
 * Deliberately narrow. `category` and `container_kind` are not editable here:
 * they decide the consumption recipe, and changing them would leave the stored
 * recipe describing the old shape - the till would then deduct the wrong stock.
 * Changing those is a deliberate replace, not a price edit.
 */
class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A manager proposes the edit; the owner approves it or writes directly.
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'price' => ['sometimes', 'numeric', 'min:0.01', 'max:999999.99'],
            'walk_in_only' => ['sometimes', 'boolean'],
            'sold_at_pos' => ['sometimes', 'boolean'],
            // Optional note carried onto the manager's approval request.
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
