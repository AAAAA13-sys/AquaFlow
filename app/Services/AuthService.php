<?php

namespace App\Services;

use App\Data\LoginResult;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/** Verify credentials and the selected login portal before creating a session. */
class AuthService
{
    public const INVALID_CREDENTIALS = 'Invalid username or password.';

    public function attemptCashier(array $credentials, ?string $ip = null): LoginResult
    {
        return $this->attempt($credentials, false, $ip);
    }

    public function attemptOwner(array $credentials, ?string $ip = null): LoginResult
    {
        return $this->attempt($credentials, true, $ip);
    }

    private function attempt(array $credentials, bool $owner, ?string $ip): LoginResult
    {
        $rawIdentifier = $owner ? trim($credentials['email']) : trim($credentials['username']);
        $identifier = strtolower($rawIdentifier);

        $user = User::query()
            ->where(function ($query) use ($identifier, $rawIdentifier, $owner) {
                if ($owner) {
                    $query->whereRaw('LOWER(email) = ?', [$identifier]);
                } else {
                    $query->where('username', $rawIdentifier)
                          ->orWhereRaw('LOWER(email) = ?', [$identifier]);
                }
            })
            ->where('is_active', true)
            ->where('is_bootstrap', false)
            ->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            $this->reject('Login failed: bad credentials.', $identifier, $ip);

            return LoginResult::failed($owner ? 'Invalid email or password.' : self::INVALID_CREDENTIALS);
        }

        if ($owner && ! $user->isAdmin()) {
            return LoginResult::failed('Use Employee Login for this account.', 403);
        }

        if (! $owner && $user->isAdmin()) {
            return LoginResult::failed('Use Admin Login for this account.', 403);
        }

        if ($owner && ! $user->email) {
            return LoginResult::failed('Owner email enrollment is required. Configure the private destination on the store host.', 403);
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
