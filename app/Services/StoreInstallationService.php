<?php

namespace App\Services;

use App\Http\Requests\CredentialRules;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Creates a store's very first account and, when that account is a bootstrap
 * account, the one-time secret that unlocks first-run setup.
 *
 * The secret is generated per installation, printed once by `aquaflow:install-store`
 * and stored only as a bcrypt hash. Nothing in the repository or the shipped
 * documentation can be used to claim a store.
 */
class StoreInstallationService
{
    /**
     * Placeholder address for the temporary bootstrap account, replaced by the
     * owner's real address during setup. Not a credential - it cannot authenticate
     * anything without the generated secret.
     */
    public const BOOTSTRAP_EMAIL = 'setup@aquaflow.invalid';

    /**
     * A console-friendly secret. The alphabet omits 0/O/1/I/L so it survives being
     * read off a terminal and retyped. Twelve characters is ~59 bits.
     */
    public const SECRET_LENGTH = 12;

    public static function generateSecret(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $secret = '';

        for ($i = 0; $i < self::SECRET_LENGTH; $i++) {
            $secret .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $secret;
    }

    /**
     * @param  array<string,mixed>  $details
     */
    public function install(array $details, bool $bootstrap = false, ?string $secret = null): bool
    {
        Validator::make($details, [
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'password' => ['required', ...CredentialRules::password()],
            'email' => ['required', 'nullable', 'email:rfc', 'max:255'],
        ])->validate();

        return DB::transaction(function () use ($details, $bootstrap, $secret): bool {
            if (User::query()->exists()) {
                return false;
            }
            User::create([
                'is_bootstrap' => $bootstrap,
                'bootstrap_secret_hash' => $bootstrap && $secret !== null ? Hash::make($secret) : null,
                'name' => $details['name'], 'username' => $details['username'],
                'password' => $details['password'],
                'email' => $details['email'] ?? null,
                'role' => User::ROLE_SUPER_ADMIN, 'is_active' => true,
            ]);
            (new CatalogSeeder)->run(false);
            Customer::firstOrCreate(['system_key' => 'walk-in'], ['name' => 'Walk-in Guest', 'address' => '-', 'contact' => '-']);

            return true;
        });
    }

    /**
     * Issue a fresh setup secret while first-run setup is still unfinished.
     *
     * Setup prints the secret once, so a mistyped or lost code would otherwise
     * strand the store; re-running Setup replaces the hash and prints a new one.
     * Returns null once a real owner account exists, which permanently closes
     * setup - the store is never re-openable by re-running the installer.
     *
     * Issuing requires running the installer on the store host, so this is not a
     * remote path: anyone able to run it already has the database. That is why
     * re-issuing stays available after the owner has entered their details - a
     * store stuck before email verification is recoverable rather than bricked.
     */
    public function reissueSecret(): ?string
    {
        return DB::transaction(function (): ?string {
            $bootstrap = User::query()->where('is_bootstrap', true)->lockForUpdate()->first();

            if ($bootstrap === null) {
                return null;
            }

            if (User::query()->where('role', User::ROLE_SUPER_ADMIN)->where('is_bootstrap', false)->exists()) {
                return null;
            }

            $secret = self::generateSecret();
            $bootstrap->forceFill(['bootstrap_secret_hash' => Hash::make($secret)])->save();

            return $secret;
        });
    }
}
