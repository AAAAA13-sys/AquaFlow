<?php

namespace App\Services;

use App\Data\LoginResult;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/** Verify credentials and active role access before creating a session. */
class AuthService
{
    public const INVALID_CREDENTIALS = 'Invalid username or password.';

    public function attempt(array $credentials, ?string $ip = null): LoginResult
    {
        $username = trim((string) ($credentials['username'] ?? ''));

        $user = User::query()
            ->where('username', $username)
            ->whereIn('role', User::ROLES)
            ->where('is_active', true)
            ->where('is_bootstrap', false)
            ->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)
            || ($user->isEmployee() && ! $user->employee?->is_active)) {
            $this->reject('Login failed: bad credentials.', $username, $ip);

            return LoginResult::failed(self::INVALID_CREDENTIALS);
        }

        return LoginResult::ok($user);
    }

    /**
     * Record a rejected attempt. The credential itself is never written.
     */
    private function reject(string $message, string $username, ?string $ip): void
    {
        Log::warning($message, [
            'username' => $username,
            'ip' => $ip,
        ]);
    }
}
