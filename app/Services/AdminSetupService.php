<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * First-run owner onboarding.
 *
 * Two steps: unlock with the per-install setup secret, then supply your own
 * details. Nothing here is a fixed credential - the secret comes from
 * StoreInstallationService and matches only the hash on the bootstrap row.
 *
 * There was a third step that verified an emailed code before the account was
 * promoted. The owner now signs in with the username and password they choose
 * here, exactly like any other account, so there is nothing left to verify.
 */
class AdminSetupService
{
    /**
     * Step 1: exchange the printed setup secret for a short-lived setup session.
     */
    public function bootstrap(array $credentials): ?User
    {
        return DB::transaction(function () use ($credentials): ?User {
            $bootstrap = User::query()->where('is_bootstrap', true)->lockForUpdate()->first();

            // A consumed or absent secret means setup is closed (or already
            // advanced past this step).
            if ($bootstrap === null || empty($bootstrap->bootstrap_secret_hash)) {
                return null;
            }

            // Once a real owner account exists, setup can never reopen.
            if (User::query()->where('role', User::ROLE_SUPER_ADMIN)->where('is_bootstrap', false)->exists()) {
                return null;
            }

            // The secret is shown upper-case; normalise so a lower-case retype works.
            $secret = strtoupper(trim((string) ($credentials['secret'] ?? '')));

            if ($secret === '' || ! Hash::check($secret, $bootstrap->bootstrap_secret_hash)) {
                return null;
            }

            return $bootstrap;
        });
    }

    /**
     * Step 2: promote the bootstrap row into the real owner account.
     *
     * The secret is consumed here. Anyone holding it could otherwise still replay
     * step 1 and overwrite these details with their own credentials, because no
     * real owner exists yet. Whoever supplies details first takes the station; a
     * station that gets stuck at this point is recovered with a fresh secret by
     * re-running Setup rather than by leaving the gate open.
     */
    public function complete(int $id, array $details, ?string $stationName = null): ?User
    {
        return DB::transaction(function () use ($id, $details, $stationName) {
            $bootstrap = User::whereKey($id)->where('is_bootstrap', true)->lockForUpdate()->first();
            if (! $bootstrap || User::where('role', User::ROLE_SUPER_ADMIN)->where('is_bootstrap', false)->exists()) {
                return null;
            }

            $bootstrap->delete();
            if ($stationName !== null) {
                SystemSetting::put('station_name', $stationName);
            }

            return User::create([
                'name' => $details['name'],
                'username' => $details['username'],
                'password' => $details['password'],
                'role' => User::ROLE_SUPER_ADMIN,
                'email' => $details['email'] ?? null,
                'is_active' => true,
            ]);
        });
    }
}
