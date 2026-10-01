<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'station_name' => ['sometimes', 'string', 'max:120'],
            'restock_lead_days' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'sus_target' => ['sometimes', 'string', 'max:60'],
        ];
    }
}
