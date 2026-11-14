<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceivePurchaseOrderRequest extends FormRequest
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
            'submission_key' => ['required', 'uuid'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.line_id' => ['required', 'integer', 'distinct'],
            'lines.*.qty' => ['required', 'integer', 'min:0', 'max:100000'],
        ];
    }

    public function toDto(): \App\Data\ReceivePurchaseOrderData
    {
        return \App\Data\ReceivePurchaseOrderData::fromArray($this->validated());
    }
}
