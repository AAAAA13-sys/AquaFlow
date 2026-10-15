<?php

namespace App\Http\Requests;

/**
 * Owner sign-in.
 *
 * Owners authenticate with the same two credentials as any other account:
 * a username and a password. The portal they land on is decided by their role,
 * not by which page they used, so `admin` is refused at the employee portal and
 * a cashier is refused here.
 */
class OwnerLoginRequest extends LoginRequest
{
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }
}
