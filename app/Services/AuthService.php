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
        $username = trim((string) ($credentials['username'] ?? ''));

        // Both portals authenticate the same way. The page decides only where a
        // successful sign-in lands; the role decides whether it is allowed at all,
        // so neither page can be used to reach the other role's workspace.
        $user = User::query()
            ->where('username', $username)
            ->where('is_active', true)
            ->where('is_bootstrap', false)
            ->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            $this->reject('Login failed: bad credentials.', $username, $ip);

            return LoginResult::failed(self::INVALID_CREDENTIALS);
        }

        if ($owner && ! $user->isAdmin()) {
            return LoginResult::failed('Use Employee Login for this account.', 403);
        }

        if (! $owner && $user->isAdmin()) {
            return LoginResult::failed('Use Admin Login for this account.', 403);
        }

        // An employee account is only as good as the person behind it. Deactivating
        // the employee record must lock the login out, not just hide the record.
        if ($user->isEmployee() && (! $user->employee || ! $user->employee->is_active)) {
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
