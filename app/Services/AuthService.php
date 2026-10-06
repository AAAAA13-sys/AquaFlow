<?php

namespace App\Services;

use App\Data\LoginResult;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Credential verification.
 *
 * Passwords and the Owner PIN are checked against bcrypt hashes here; the client
 * never receives either. Controllers own the HTTP concerns (session, redirects);
 * this service owns the rules.
 *
 * Failed attempts are logged with the username and IP but never the credential.
 */
class AuthService
{
    /** US-03: the exact inline banner string for a valid password + wrong PIN. */
    public const INVALID_OWNER_PIN = 'Invalid Owner PIN';

    public const INVALID_CREDENTIALS = 'Invalid username or password.';

    public const INVALID_OWNER_CREDENTIALS = 'Invalid owner username or password.';

    /**
     * @param  array{username: string, password: string}  $credentials
     */
    public function attemptCashier(array $credentials, ?string $ip = null): LoginResult
    {
        $user = $this->findActive($credentials['username']);

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            $this->reject('Cashier login failed: bad credentials.', $credentials['username'], $ip);

            return LoginResult::failed(self::INVALID_CREDENTIALS);
        }

        // Check the role *before* authenticating: signing an owner in and back
        // out would migrate the session and rotate the CSRF token.
        if ($user->isAdmin()) {
            $this->reject('Cashier login failed: owner account on the cashier form.', $credentials['username'], $ip);

            return LoginResult::failed(
                'Owner accounts use the separate Owner Login page.',
                403,
            );
        }

        return LoginResult::ok($user);
    }

    /**
     * US-02/US-03: three factors, and a wrong PIN must never yield a session.
     *
     * @param  array{username: string, password: string, pin: string}  $credentials
     */
    public function attemptOwner(array $credentials, ?string $ip = null): LoginResult
    {
        $user = $this->findActive($credentials['username']);

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            $this->reject('Owner login failed: bad credentials.', $credentials['username'], $ip);

            return LoginResult::failed(self::INVALID_OWNER_CREDENTIALS);
        }

        if (! $user->isAdmin()) {
            $this->reject('Owner login failed: not an owner account.', $credentials['username'], $ip);

            return LoginResult::failed('Invalid owner account.');
        }

        if (empty($user->pin) || ! Hash::check($credentials['pin'], $user->pin)) {
            $this->reject('Owner login failed: wrong Owner PIN.', $credentials['username'], $ip);

            return LoginResult::failed(self::INVALID_OWNER_PIN);
        }

        return LoginResult::ok($user);
    }

    private function findActive(string $username): ?User
    {
        return User::query()
            ->where('username', $username)
            ->where('is_active', true)
            ->first();
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
