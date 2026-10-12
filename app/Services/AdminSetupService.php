<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * First-run owner onboarding.
 *
 * Three steps: unlock with the per-install setup secret, supply your own details,
 * then verify your email. Nothing here is a fixed credential - the secret comes
 * from StoreInstallationService and matches only the hash on the bootstrap row.
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
            if (User::query()->where('role', 'admin')->where('is_bootstrap', false)->exists()) {
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
     * Step 2: attach the owner's own details and queue the verification email.
     *
     * The secret is consumed here. Anyone holding it could otherwise still replay
     * step 1 and overwrite these details with their own address, because no real
     * owner exists yet. Whoever supplies details first takes the store; a store
     * that gets stuck at this point is recovered with a fresh secret by re-running
     * Setup rather than by leaving the gate open.
     */
    public function prepare(int $id, array $details, string $session): string
    {
        return DB::transaction(function () use ($id, $details, $session) {
            $user = User::whereKey($id)->where('is_bootstrap', true)->lockForUpdate()->firstOrFail();
            abort_if(User::where('role', 'admin')->where('is_bootstrap', false)->exists(), 403);
            // Changes roll back if the mail challenge cannot be queued.
            $user->update([
                'name' => $details['name'],
                'email' => strtolower($details['email']),
                'password' => $details['password'],
                'bootstrap_secret_hash' => null,
            ]);

            return app(OwnerEmailService::class)->issue($user, $session);
        });
    }

    /**
     * Step 3: verify the emailed code and promote the bootstrap row into the real
     * owner account.
     */
    public function complete(int $id, string $challenge, string $session, string $code, ?string $stationName = null): ?User
    {
        return DB::transaction(function () use ($id, $challenge, $session, $code, $stationName) {
            $bootstrap = User::whereKey($id)->where('is_bootstrap', true)->lockForUpdate()->first();
            if (! $bootstrap || User::where('role', 'admin')->where('is_bootstrap', false)->exists()) {
                return null;
            }
            $verified = app(OwnerEmailService::class)->verify($challenge, $session, $code);
            if (! $verified || $verified->id !== $bootstrap->id) {
                return null;
            }
            $details = ['name' => $bootstrap->name, 'email' => $bootstrap->email, 'password' => $bootstrap->password,
                'username' => 'owner_'.Str::lower(Str::random(16)), 'role' => 'admin', 'is_active' => true];
            $bootstrap->delete();
            if ($stationName !== null) {
                \App\Models\SystemSetting::put('station_name', $stationName);
            }

            return User::create($details);
        });
    }
}
