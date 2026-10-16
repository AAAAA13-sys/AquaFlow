<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('user') instanceof User && ($this->user()?->canManageUser($this->route('user')) ?? false);
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
            'role' => ['sometimes', Rule::in($this->user()?->isSuperAdmin() ? User::ROLES : User::STAFF_ROLES)],
            'password' => [Rule::requiredIf(in_array($this->input('role'), [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN], true) && $this->input('role') !== $this->route('user')?->role), 'nullable', ...CredentialRules::password()],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
