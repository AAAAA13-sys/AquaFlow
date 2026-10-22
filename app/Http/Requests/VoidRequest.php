<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VoidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canHandleCash();
    }

    public function rules(): array
    {
        return [
            'submission_key' => ['required', 'uuid'],
            'transaction_id' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'approver_username' => ['required', 'string', 'max:255'],
            'approver_password' => ['required', 'string', 'max:255'],
            'cart' => ['required_without:transaction_id', \Illuminate\Validation\Rule::prohibitedIf($this->filled('transaction_id')), 'array', 'min:1', 'max:100'],
            'cart.*.product_id' => ['required', 'string', 'max:100'],
            'cart.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
        ];
    }
}
