<?php

namespace App\Http\Requests;

class RemittanceRequest extends SettleDebtRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canHandleCash() ?? false;
    }

    public function rules(): array
    {
        return parent::rules() + ['note' => ['nullable', 'string', 'min:5', 'max:500']];
    }
}
