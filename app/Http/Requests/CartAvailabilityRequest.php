<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CartAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['items' => ['present', 'array', 'max:50'], 'items.*.product_id' => ['required', 'string', 'max:30'], 'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20']];
    }
}
