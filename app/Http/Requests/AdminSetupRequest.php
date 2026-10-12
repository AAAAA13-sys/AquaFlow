<?php

namespace App\Http\Requests;

use App\Services\StoreInstallationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminSetupRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
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
            'email' => ['required', 'email:rfc', 'max:255', Rule::notIn([StoreInstallationService::BOOTSTRAP_EMAIL]), Rule::unique('users', 'email')->ignore($this->session()->get('setup_user'))],
            'password' => ['required', 'confirmed', ...CredentialRules::password()],
        ];
    }
}
