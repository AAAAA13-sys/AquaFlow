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
            'username' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:users,username'],
            'password' => ['required', 'string', 'min:4', 'max:255'],
            'role' => ['required', Rule::in([User::ROLE_CASHIER, User::ROLE_ADMIN])],
            'name' => ['required', 'string', 'max:100'],
            'pin' => ['nullable', 'string', 'min:4', 'max:12'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
