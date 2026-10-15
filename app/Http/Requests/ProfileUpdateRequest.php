<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:20'],
            'current_password' => ['nullable', 'string', 'required_with:new_password'],
            'new_password' => [$this->user()?->must_change_password ? 'required' : 'nullable', ...CredentialRules::password(), 'different:current_password'],
            'new_password_confirmation' => ['nullable', 'string', 'required_with:new_password', 'same:new_password'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Display name is required.',
            'current_password.required_with' => 'Current password is required to change your password.',
            'new_password.min' => 'New password must be at least 8 characters.',
            'new_password_confirmation.same' => 'Password confirmation does not match.',
            'new_password_confirmation.required_with' => 'Please confirm your new password.',
        ];
    }
}
