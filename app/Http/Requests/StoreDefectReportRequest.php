<?php

namespace App\Http\Requests;

use App\Models\InventoryItem;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDefectReportRequest extends FormRequest
{
    /**
     * Any signed-in station account may file a defect report. The route group
     * already gates authentication, and employees are allowed on this URI by
     * EnsureRoleAccess, so there is no role check here to repeat.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'inventory_item_id' => ['required', 'integer', Rule::exists('inventory', 'id')],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * Stock on hand is only known once the row is resolved, so the ceiling on
     * `quantity` is attached here rather than as a static rule. The message must
     * tell the staff member why their count was refused.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $item = InventoryItem::query()->find($this->input('inventory_item_id'));
            $quantity = (int) $this->input('quantity', 0);

            if ($item === null) {
                return;
            }

            if ($item->retired_at !== null) {
                $validator->errors()->add('inventory_item_id', 'That stock item has been retired.');
            }

            if ($quantity > $item->stock_on_hand) {
                $validator->errors()->add('quantity', 'Quantity exceeds available stock');
            }
        });
    }
}
