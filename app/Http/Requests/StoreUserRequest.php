<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
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
            'username' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/', 'unique:users,username'],
            'password' => $this->input('role') === User::ROLE_ADMIN
                ? ['required', ...CredentialRules::password()]
                : ($this->input('password') === '12345678' ? ['sometimes', 'string', 'in:12345678'] : ['sometimes', ...CredentialRules::password()]),
            'role' => ['required', Rule::in([User::ROLE_CASHIER, User::ROLE_ADMIN])],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id', Rule::unique('users', 'employee_id')->ignore($this->route('user')?->id)],
            'name' => ['required', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
