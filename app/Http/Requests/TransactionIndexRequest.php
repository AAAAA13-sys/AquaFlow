<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransactionIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'to' => ['nullable', 'date_format:Y-m-d\TH:i', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
            'days' => ['nullable', 'integer', 'min:0', 'max:36500'],
            'type' => ['nullable', 'in:All,Walk-in,Delivery,Debt Payment'],
            'pay' => ['nullable', 'in:All,Cash,Account'],
            'paginated' => ['sometimes', 'boolean'], 'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
