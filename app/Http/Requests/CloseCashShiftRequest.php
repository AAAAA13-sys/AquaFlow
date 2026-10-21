<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CloseCashShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canHandleCash() ?? false;
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'shift_id' => ['required', 'integer', 'min:1'],
            'expected_cash' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'counted_cash' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}