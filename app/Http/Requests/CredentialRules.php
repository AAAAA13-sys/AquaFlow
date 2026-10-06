<?php
namespace App\Http\Requests;

use Illuminate\Validation\Rules\Password;

final class CredentialRules
{
    public static function password(): array
    {
        return ['string', 'max:64', 'regex:/^[\x21-\x7E]+$/', Password::min(8)->mixedCase()->numbers()];
    }

    public static function pin(): array
    {
        return ['string', 'regex:/^[0-9]{4,12}$/'];
    }
}
