<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SettleDebtRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'submission_key' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
        ];
    }
}
