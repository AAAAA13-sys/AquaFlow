<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->input('context') === 'terminal' || ! is_string($this->input('contact'))) {
            return;
        }
        $contact = preg_replace('/[\s()-]+/', '', $this->input('contact'));
        $this->merge(['contact' => $contact]);
    }

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
            'name' => ['required', 'string', 'max:150', 'regex:/^(?=.*\p{L})[\p{L}\s.\'-]+$/u'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact' => ['required', 'string', $this->input('context') === 'terminal'
                ? 'regex:/^09[0-9]{9}\z/'
                : 'regex:/^(?:09|\+639)[0-9]{9}\z/'],
        ];
    }

    public function messages(): array
    {
        return ['contact.required' => 'A mobile number is required for SMS reminders.',
            'contact.regex' => $this->input('context') === 'terminal'
                ? 'Enter an 11-digit mobile number starting with 09.'
                : 'Enter a Philippine mobile number starting with 09 or +639.'];
    }
}
