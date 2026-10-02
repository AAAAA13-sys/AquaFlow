<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSupplierRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:120'],
            'supplied_items' => ['sometimes', 'string', 'max:255'],
            'lead_time_days' => ['sometimes', 'integer', 'min:1', 'max:14'],
            'contact' => ['sometimes', 'nullable', 'string', 'max:50'],
            'last_delivery' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
