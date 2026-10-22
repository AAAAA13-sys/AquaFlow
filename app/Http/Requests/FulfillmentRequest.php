<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FulfillmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->input('action') !== 'assign';
    }

    public function rules(): array
    {
        return ['action' => ['required', 'in:ready,handover,reset_code,dispatch,deliver,issue'], 'version' => ['required', 'integer', 'min:0'], 'submission_key' => ['required', 'uuid'], 'reason' => ['required_if:action,issue', 'nullable', 'string', 'min:5', 'max:500'],
            'handover_quantity' => ['required_if:action,handover', 'integer', 'min:1', 'max:1000'],
            'photo' => ['required_if:action,deliver', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=320,min_height=240,max_width=6000,max_height=6000'],
            'recipient_name' => ['required_if:action,deliver', 'string', 'min:2', 'max:100'],
            'recipient_code' => ['required_if:action,deliver', 'regex:/^[0-9]{8}$/'],
            'received_quantity' => ['required_if:action,deliver', 'integer', 'min:1', 'max:1000'],
            'reported_cash' => ['required_if:action,deliver', 'numeric', 'decimal:0,2', 'min:0', 'max:1000000'],
            'collection_note' => ['nullable', 'string', 'min:5', 'max:500']];
    }
}
