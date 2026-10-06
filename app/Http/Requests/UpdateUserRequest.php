<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:100'],
            'role' => ['sometimes', Rule::in([User::ROLE_CASHIER, User::ROLE_ADMIN])],
            'password' => ['sometimes', 'nullable', ...CredentialRules::password()],
            'pin' => ['sometimes', 'nullable', ...CredentialRules::pin()],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
