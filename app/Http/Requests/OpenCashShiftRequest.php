<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpenCashShiftRequest extends FormRequest
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
            'opening_float' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:1000000'],
            'opening_note' => ['nullable', 'string', 'max:500'],
        ];
    }
}