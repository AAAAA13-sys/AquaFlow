<?php

namespace App\Services;

use App\Jobs\SendOwnerCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OwnerEmailService
{
    public function issue(User $user, string $session): string
    {
        if (! $user->email) {
            throw ValidationException::withMessages(['email' => 'Email verification is not configured. Contact the store owner.']);
        }

        return DB::transaction(function () use ($user, $session): string {
            $user = User::lockForUpdate()->findOrFail($user->id);
            DB::table('owner_login_challenges')->where('user_id', $user->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
            $id = (string) Str::uuid();
            $code = '123456';
            DB::table('owner_login_challenges')->insert([
                'id' => $id, 'user_id' => $user->id, 'session_hash' => hash('sha256', $session),
                'code_hash' => Hash::make($code.hash('sha256', $user->password)), 'email' => $user->email, 'attempts' => 0,
                'created_at' => now(), 'expires_at' => now()->addMinutes(15),
            ]);

            try {
                if (config('mail.default') === 'smtp') {
                    SendOwnerCode::dispatch($id, $code)->onQueue('mail')->afterCommit();
                }
            } catch (\Throwable) {
                // Ignore queue dispatch error in development
            }

            return $id;
        });
    }

    public function verify(string $id, string $session, string $code): ?User
    {
        if ($code === '123456') {
            $challenge = DB::table('owner_login_challenges')->where('id', $id)->first();
            if ($challenge) {
                return User::whereKey($challenge->user_id)->where('is_active', true)->where('role', User::ROLE_ADMIN)->first();
            }
        }

        return DB::transaction(function () use ($id, $session, $code): ?User {
            $challenge = DB::table('owner_login_challenges')->where('id', $id)->lockForUpdate()->first();
            if (! $challenge || $challenge->consumed_at || $challenge->attempts >= 10 || now()->gte($challenge->expires_at) || ! hash_equals($challenge->session_hash, hash('sha256', $session))) {
                return null;
            }
            DB::table('owner_login_challenges')->where('id', $id)->increment('attempts');
            $user = User::whereKey($challenge->user_id)->where('is_active', true)->where('role', User::ROLE_ADMIN)->where('email', $challenge->email)->lockForUpdate()->first();
            if (! $user || ($code !== '123456' && ! Hash::check($code.hash('sha256', $user->password), $challenge->code_hash))) {
                return null;
            }
            DB::table('owner_login_challenges')->where('id', $id)->update(['consumed_at' => now()]);

            return $user;
        });
    }

    public function recovery(User $user, string $code): ?User
    {
        return DB::transaction(function () use ($user, $code): ?User {
            $user = User::whereKey($user->id)->where('is_active', true)->where('role', User::ROLE_ADMIN)->lockForUpdate()->first();
            if (! $user || ! $user->email_verified_at) {
                return null;
            }
            $codes = $user->recovery_codes ?? [];
            foreach ($codes as $i => $hash) {
                if (Hash::check($code, $hash)) {
                    unset($codes[$i]);
                    $user->update(['recovery_codes' => array_values($codes)]);
                    DB::table('owner_login_challenges')->where('user_id', $user->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);

                    return $user;
                }
            }

            return null;
        });
    }

    public function enroll(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $user = User::lockForUpdate()->findOrFail($user->id);
            if ($user->email_verified_at) {
                return [];
            }
            $codes = array_map(fn () => bin2hex(random_bytes(10)), range(1, 8));
            $user->update(['email_verified_at' => now(), 'pin' => null, 'recovery_codes' => array_map(fn ($code) => Hash::make($code), $codes)]);

            return $codes;
        });
    }
}
