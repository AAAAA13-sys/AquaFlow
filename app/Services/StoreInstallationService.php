<?php

namespace App\Services;

use App\Http\Requests\CredentialRules;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class StoreInstallationService
{
    public function install(array $details): bool
    {
        Validator::make($details, [
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'password' => ['required', ...CredentialRules::password()],
            'pin' => ['required', ...CredentialRules::pin()],
        ])->validate();

        return DB::transaction(function () use ($details): bool {
            if (User::query()->exists()) {
                return false;
            }
            User::create([
                'name' => $details['name'], 'username' => $details['username'],
                'password' => $details['password'], 'pin' => Hash::make($details['pin']),
                'role' => User::ROLE_ADMIN, 'is_active' => true,
            ]);
            (new CatalogSeeder)->run(false);

            return true;
        });
    }
}
