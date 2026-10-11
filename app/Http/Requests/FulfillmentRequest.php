<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FulfillmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['action' => ['required', 'in:assign,ready,dispatch,deliver,issue'], 'version' => ['required', 'integer', 'min:0'], 'submission_key' => ['required', 'uuid'], 'rider_id' => ['nullable', 'integer'], 'reason' => ['nullable', 'string', 'min:5', 'max:500']];
    }
}
