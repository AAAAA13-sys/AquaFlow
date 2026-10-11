<?php

namespace App\Services;

use App\Http\Requests\CredentialRules;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class StoreInstallationService
{
    public function install(array $details, bool $bootstrap = false): bool
    {
        Validator::make($details, [
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'password' => ['required', ...CredentialRules::password()],
            'email' => ['required', 'nullable', 'email:rfc', 'max:255'],
            'pin' => ['required_without:email', 'nullable', ...CredentialRules::pin()],
        ])->validate();

        return DB::transaction(function () use ($details, $bootstrap): bool {
            if (User::query()->exists()) {
                return false;
            }
            User::create([
                'is_bootstrap' => $bootstrap,
                'name' => $details['name'], 'username' => $details['username'],
                'password' => $details['password'], 'pin' => empty($details['pin']) ? null : Hash::make($details['pin']),
                'email' => $details['email'] ?? null,
                'role' => User::ROLE_ADMIN, 'is_active' => true,
            ]);
            (new CatalogSeeder)->run(false);
            Customer::firstOrCreate(['system_key' => 'walk-in'], ['name' => 'Walk-in Guest', 'address' => '-', 'contact' => '-']);

            return true;
        });
    }
}
