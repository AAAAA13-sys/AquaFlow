<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminSetupService
{
    public const EMAIL = 'admin@aquaflow.com';

    public const PASSWORD = 'Adm1nP4ss!123';

    public const CODE = '571067';

    public function bootstrap(array $credentials): ?User
    {
        if (! hash_equals(self::EMAIL, strtolower($credentials['email'])) || ! hash_equals(self::PASSWORD, $credentials['password']) || ! hash_equals(self::CODE, $credentials['code'])) {
            return null;
        }
        if (User::where('role', 'admin')->where('is_bootstrap', false)->exists()) {
            return null;
        }

        return User::where('is_bootstrap', true)->first();
    }

    public function prepare(int $id, array $details, string $session): string
    {
        return DB::transaction(function () use ($id, $details, $session) {
            $user = User::whereKey($id)->where('is_bootstrap', true)->lockForUpdate()->firstOrFail();
            abort_if(User::where('role', 'admin')->where('is_bootstrap', false)->exists(), 403);
            // Changes roll back if the mail challenge cannot be queued.
            $user->update(['name' => $details['name'], 'email' => strtolower($details['email']), 'password' => $details['password']]);

            return app(OwnerEmailService::class)->issue($user, $session);
        });
    }

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
