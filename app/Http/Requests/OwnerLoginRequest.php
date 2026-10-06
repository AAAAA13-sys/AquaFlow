<?php

namespace App\Http\Requests;

class OwnerLoginRequest extends LoginRequest
{
    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'pin' => ['required', ...CredentialRules::pin()],
        ];
    }
}
