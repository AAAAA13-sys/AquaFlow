<?php

namespace App\Http\Requests;

class OwnerLoginRequest extends LoginRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'pin' => ['prohibited'],
        ];
    }
}
