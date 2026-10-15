<?php

namespace App\Http\Requests;

use App\Services\StoreInstallationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminSetupRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // Only normalise an address that was actually supplied. Coercing an
        // absent field to '' would store an empty string as the owner's contact.
        $email = trim((string) $this->input('email'));
        $this->merge(['email' => $email === '' ? null : strtolower($email)]);
    }

    public function authorize(): bool
    {
        return $this->session()->has('setup_user') && $this->session()->get('setup_expires', 0) > now()->timestamp;
    }

    public function rules(): array
    {
        return [
            'station_name' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('users', 'username')],
            // Email is optional now that nothing is sent to it. It is kept on the
            // account as a contact, and the portal renders it if present.
            'email' => ['nullable', 'email:rfc', 'max:255', Rule::notIn([StoreInstallationService::BOOTSTRAP_EMAIL]), Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', ...CredentialRules::password()],
        ];
    }
}
