<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseOrderRequest extends FormRequest
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
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'expected_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.inventory_item_id' => ['required', 'integer', 'distinct',
                Rule::exists('inventory', 'id')->whereNull('retired_at')],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:100000'],
            'lines.*.unit_cost' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:1000000'],
        ];
    }

    /** Largest value purchase_orders.total_cost (decimal 12,2) can hold. */
    private const MAX_TOTAL = 9999999999.99;

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $v): void {
            if ($v->errors()->has('lines') || collect($v->errors()->keys())->contains(fn ($k) => str_starts_with($k, 'lines.'))) {
                return;
            }
            $total = 0.0;
            foreach ((array) $this->input('lines', []) as $line) {
                $total += (float) ($line['qty'] ?? 0) * (float) ($line['unit_cost'] ?? 0);
            }
            if ($total > self::MAX_TOTAL) {
                $v->errors()->add('lines', 'The order total is too large. Reduce quantities or unit costs.');
            }
        });
    }

    /**
     * @return array<string,string>
     */
    public function messages(): array
    {
        return [
            'lines.*.inventory_item_id.distinct' => 'Each item can appear only once on a purchase order.',
            'lines.*.inventory_item_id.exists' => 'That stock item does not exist or is retired.',
        ];
    }
}
