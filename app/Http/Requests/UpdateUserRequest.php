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
            'email' => ['sometimes', 'required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user')?->id)],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id', Rule::unique('users', 'employee_id')->ignore($this->route('user')?->id)],
            'name' => ['sometimes', 'string', 'max:20'],
            'role' => ['sometimes', Rule::in([User::ROLE_CASHIER, User::ROLE_ADMIN])],
            'password' => [Rule::requiredIf($this->input('role') === User::ROLE_ADMIN && $this->route('user')?->role !== User::ROLE_ADMIN), 'nullable', ...CredentialRules::password()],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
