<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInventoryRequest extends FormRequest
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
            'lead_time_days' => ['sometimes', 'integer', 'min:1', 'max:14'],
            'direction' => ['sometimes', 'integer', 'in:-1,1'],
        ];
    }
}
